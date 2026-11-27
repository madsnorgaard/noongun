<?php

namespace App\Http\Presenters;

use App\Domain\Channels\ChannelManager;
use App\Domain\Channels\ChannelStatus;
use App\Domain\Channels\Contracts\ChannelCapabilities;
use App\Domain\Channels\Contracts\MediaLinkRule;
use App\Domain\Channels\Exceptions\ChannelAdapterNotAvailable;
use App\Domain\Channels\Models\Channel;
use App\Domain\Content\Actions\DeletePost;
use App\Domain\Content\Exceptions\PostNotPublishable;
use App\Domain\Content\Media\MediaConfig;
use App\Domain\Content\Models\MediaAttachment;
use App\Domain\Content\Models\Post;
use App\Domain\Content\Models\PostVariant;
use App\Domain\Content\PostPreconditions;
use App\Domain\Content\PostState;
use App\Domain\Drafting\DraftingConfig;
use App\Domain\Drafting\Models\DraftRun;
use App\Domain\Workspaces\CurrentWorkspace;

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
            // Worth a look, and never a reason to refuse: an image without
            // a description, a link that moves into the text. Keyed like
            // problems.
            'advice' => (object) ($state === PostState::Draft ? $this->preconditions->advice($post) : []),
            'origin' => $this->origin($post),
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
     * Where a drafted post came from, read from its draft run, or null
     * for a post written by hand. The rationale and the alternative text
     * are what a model wrote: the pages show them as plain text.
     *
     * @return array<string, mixed>|null
     */
    public function origin(Post $post): ?array
    {
        $run = $post->draftRun;

        if ($run === null) {
            return null;
        }

        return [
            'pillar' => $run->pillar?->name,
            'idea' => $run->idea?->title,
            'provider' => DraftingConfig::label($run->provider),
            'model' => $run->model,
            'rationale' => $run->rationale,
            'alt_text' => $run->alt_text,
            // The model suggested a link the author never gave. It was left out.
            'link_dropped' => $run->link_dropped,
            // Channels the draft was planned for and could not be written
            // for, because they could not publish when it was drafted.
            'left_out' => $this->leftOut($run),
            // How many approved posts went along as examples of the voice.
            'examples' => $run->exampleCount(),
            'drafted_at' => $run->finished_at?->toIso8601String(),
            // What this one draft used: tokens as the provider reported
            // them, and the estimate of the price table when there is one.
            'input_tokens' => $run->input_tokens,
            'output_tokens' => $run->output_tokens,
            'cost' => $run->cost_estimate !== null ? Money::used($run->cost_estimate) : null,
            'currency' => $run->cost_currency,
        ];
    }

    /**
     * A fingerprint of what a human reads before signing off: the planned
     * time, every text, every link with its card, and the images of every
     * text in their order, each with its alternative text. The approve
     * request sends it back, so that a post that was edited in the
     * meantime is not approved unseen.
     */
    public function version(Post $post): string
    {
        $content = $post->variants->sortBy('id')->values()
            ->map(fn (PostVariant $variant): array => [
                $variant->id, $variant->channel_id, $variant->body, $variant->link, $variant->link_title, $variant->link_description,
                $variant->attachments->map(fn (MediaAttachment $attachment): array => [$attachment->media_id, $attachment->alt_text])->values()->all(),
            ])
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
            ->map(function (Channel $channel): array {
                $capabilities = $this->capabilities($channel);

                return [
                    'id' => $channel->id,
                    'display_name' => $channel->display_name,
                    'platform_label' => $this->platformLabel($channel->platform),
                    'can_publish' => $channel->canPublish(),
                    'problem' => PostPreconditions::channelProblem($channel)
                        ?? ($capabilities === null ? PostPreconditions::platformUnavailable($channel) : null),
                    'max_body_length' => $capabilities?->maxBodyLength,
                    'link_previews' => $capabilities?->linkPreviews,
                    // Set when the platform shows a link only as the card
                    // the author types: the editor then asks for one.
                    'link_title_length' => $capabilities?->linkTitleLength,
                    'link_description_length' => $capabilities?->linkDescriptionLength,
                    'media' => self::mediaRules($capabilities),
                ];
            })
            ->all());
    }

    /**
     * @return array<string, mixed>
     */
    private function variant(PostVariant $variant): array
    {
        $channel = $variant->channel;
        $capabilities = $channel !== null ? $this->capabilities($channel) : null;
        // The card of a link belongs to a platform that shows one. On any
        // other channel a stored title is never used, so it is not shown.
        $card = $capabilities?->needsLinkCard() ?? false;

        return [
            'id' => $variant->id,
            'channel_id' => $variant->channel_id,
            'channel_name' => $channel->display_name ?? '',
            'platform_label' => $channel !== null ? $this->platformLabel($channel->platform) : '',
            'max_body_length' => $capabilities?->maxBodyLength,
            'body' => $variant->body,
            'link' => $variant->link,
            'link_title' => $card ? $variant->link_title : null,
            'link_description' => $card ? $variant->link_description : null,
            // The link with its campaign parameters. Where the images take
            // the place of the link card, this is the last line of the
            // text, and the editor counts it as the readiness check does.
            'link_as_sent' => $variant->linkToSend(),
            'media' => $variant->images()
                ->filter(fn (MediaAttachment $attachment): bool => $attachment->media !== null)
                ->map(fn (MediaAttachment $attachment): array => [...MediaPresenter::present($attachment->image()), 'alt_text' => $attachment->alt_text])
                ->values()
                ->all(),
            'media_rules' => self::mediaRules($capabilities),
            'published' => $variant->isPublished(),
            'outcome_unknown' => $variant->hasUnknownOutcome(),
            'permalink' => $variant->permalink,
            'published_at' => $variant->published_at?->toIso8601String(),
            'error' => $variant->isPublished() ? null : $variant->error,
        ];
    }

    /**
     * What a platform takes in images, for the editor: how many, which
     * types, how large, whether it takes an alternative text and how long,
     * and what becomes of the link of a text that has images
     * (MediaLinkRule). max_items is 0 for a platform that takes none.
     *
     * @return array{max_items: int, types: list<string>, max_bytes: int|null, max_pixels: int|null, alt_text_length: int|null, link_rule: string}
     */
    private static function mediaRules(?ChannelCapabilities $capabilities): array
    {
        $accepts = $capabilities?->acceptsMedia() ?? false;

        return [
            'max_items' => $accepts ? min($capabilities->maxMediaItems, MediaConfig::maxPerVariant()) : 0,
            'types' => $accepts ? $capabilities->mediaTypes : [],
            'max_bytes' => $capabilities?->maxMediaBytes,
            'max_pixels' => $capabilities?->maxMediaPixels,
            'alt_text_length' => $capabilities?->altTextLength,
            'link_rule' => ($capabilities->mediaLinkRule ?? MediaLinkRule::InText)->value,
        ];
    }

    /**
     * @return list<string>
     */
    private function leftOut(DraftRun $run): array
    {
        $ids = array_values(array_filter((array) ($run->request['left_out_channel_ids'] ?? []), is_numeric(...)));

        if ($ids === []) {
            return [];
        }

        return array_values(Channel::query()->whereKey($ids)->orderBy('id')->pluck('display_name')->all());
    }

    private function capabilities(Channel $channel): ?ChannelCapabilities
    {
        try {
            return $this->channels->for($channel)->capabilities();
        } catch (ChannelAdapterNotAvailable) {
            return null;
        }
    }

    private function platformLabel(string $platform): string
    {
        return PlatformLabel::for($platform);
    }
}
