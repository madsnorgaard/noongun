<?php

namespace App\Http\Presenters;

use App\Domain\Channels\ChannelManager;
use App\Domain\Channels\ChannelStatus;
use App\Domain\Channels\Exceptions\ChannelAdapterNotAvailable;
use App\Domain\Channels\Models\Channel;
use App\Domain\Content\Actions\DeletePost;
use App\Domain\Content\Exceptions\PostNotPublishable;
use App\Domain\Content\Models\Post;
use App\Domain\Content\Models\PostVariant;
use App\Domain\Content\PostPreconditions;
use App\Domain\Content\PostState;
use App\Domain\Workspaces\CurrentWorkspace;
use Illuminate\Support\Str;

/**
 * What the queue, the calendar and the editor show of a post. Times go out
 * as UTC instants; the wall-clock value for the time field is given in the
 * workspace timezone.
 */
final class PostPresenter
{
    /** The format of a datetime-local field, and of the planned time in requests. */
    public const LOCAL_FORMAT = 'Y-m-d\TH:i';

    public function __construct(
        private readonly ChannelManager $channels,
        private readonly PostPreconditions $preconditions,
        private readonly CurrentWorkspace $currentWorkspace,
    ) {}

    /**
     * @param  bool  $mayChange  whether the viewer is an owner or editor
     * @return array<string, mixed>
     */
    public function present(Post $post, bool $mayChange): array
    {
        $workspace = $this->currentWorkspace->getOrFail();
        $state = $post->state;

        return [
            'id' => $post->id,
            'state' => $state->value,
            'scheduled_at' => $post->scheduled_at?->toIso8601String(),
            'scheduled_local' => $post->scheduled_at !== null
                ? $workspace->toLocal($post->scheduled_at)->format(self::LOCAL_FORMAT)
                : null,
            'approval' => $post->approved_at !== null
                ? ['at' => $post->approved_at->toIso8601String(), 'by' => $post->approver?->name]
                : null,
            'updated_at' => $post->updated_at?->toIso8601String(),
            'version' => $this->version($post),
            'variants' => $post->variants->sortBy('id')->values()->map($this->variant(...))->all(),
            'problems' => (object) $this->problems($post),
            'can' => [
                'update' => $mayChange && $state === PostState::Draft,
                'approve' => $mayChange && $state === PostState::Draft,
                'reject' => $mayChange && $state === PostState::Draft,
                'schedule' => $mayChange && in_array($state, [PostState::Approved, PostState::Scheduled], true),
                'unschedule' => $mayChange && $state === PostState::Scheduled,
                'return_to_draft' => $mayChange && in_array($state, [PostState::Approved, PostState::Scheduled, PostState::Failed, PostState::Rejected], true),
                'retry' => $mayChange && $state === PostState::Failed,
                'delete' => $mayChange && DeletePost::allows($post),
            ],
        ];
    }

    /**
     * A fingerprint of what a human reads before signing off: the planned
     * time and every text. The approve request sends it back, so that a
     * post that was edited in the meantime is not approved unseen.
     */
    public function version(Post $post): string
    {
        $content = $post->variants->sortBy('id')->values()
            ->map(fn (PostVariant $variant): array => [$variant->id, $variant->channel_id, $variant->body, $variant->link])
            ->all();

        return substr(hash('sha256', (string) json_encode([$post->scheduled_at?->getTimestamp(), $content])), 0, 20);
    }

    /**
     * Why the post cannot be approved, scheduled or sent again as it stands,
     * keyed as PostNotPublishable keys its errors. Empty for states where
     * the question does not arise.
     *
     * @return array<string, non-empty-list<string>>
     */
    public function problems(Post $post): array
    {
        if (! in_array($post->state, [PostState::Draft, PostState::Approved, PostState::Scheduled, PostState::Failed], true)) {
            return [];
        }

        $problems = $this->preconditions->problems($post);

        if ($post->state === PostState::Draft && $post->scheduled_at !== null) {
            try {
                $this->preconditions->assertSensibleTime($post, $post->scheduled_at);
            } catch (PostNotPublishable $exception) {
                $problems += $exception->errors();
            }
        }

        return $problems;
    }

    /**
     * The channels the editor offers: every channel that is neither
     * disconnected nor waiting to be confirmed, and whatever the post
     * already uses.
     *
     * @return list<array<string, mixed>>
     */
    public function channelOptions(?Post $post): array
    {
        $used = $post?->variants->pluck('channel_id')->all() ?? [];

        return array_values(Channel::query()
            ->where(fn ($query) => $query->whereNotIn('status', [ChannelStatus::Disconnected, ChannelStatus::Pending])->orWhereIn('id', $used))
            ->orderBy('platform')
            ->orderBy('display_name')
            ->get()
            ->map(fn (Channel $channel): array => [
                'id' => $channel->id,
                'display_name' => $channel->display_name,
                'platform_label' => $this->platformLabel($channel->platform),
                'can_publish' => $channel->canPublish(),
                'problem' => PostPreconditions::channelProblem($channel),
                'max_body_length' => $this->maxBodyLength($channel),
            ])
            ->all());
    }

    /**
     * @return array<string, mixed>
     */
    private function variant(PostVariant $variant): array
    {
        $channel = $variant->channel;

        return [
            'id' => $variant->id,
            'channel_id' => $variant->channel_id,
            'channel_name' => $channel->display_name ?? '',
            'platform_label' => $channel !== null ? $this->platformLabel($channel->platform) : '',
            'max_body_length' => $channel !== null ? $this->maxBodyLength($channel) : null,
            'body' => $variant->body,
            'link' => $variant->link,
            'published' => $variant->isPublished(),
            'permalink' => $variant->permalink,
            'published_at' => $variant->published_at?->toIso8601String(),
            'error' => $variant->isPublished() ? null : $variant->error,
        ];
    }

    private function maxBodyLength(Channel $channel): ?int
    {
        try {
            return $this->channels->for($channel)->capabilities()->maxBodyLength;
        } catch (ChannelAdapterNotAvailable) {
            return null;
        }
    }

    private function platformLabel(string $platform): string
    {
        return Str::headline($platform);
    }
}
