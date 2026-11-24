<?php

namespace App\Domain\Content;

use App\Domain\Channels\ChannelManager;
use App\Domain\Channels\ChannelStatus;
use App\Domain\Channels\Exceptions\ChannelAdapterNotAvailable;
use App\Domain\Channels\Models\Channel;
use App\Domain\Content\Exceptions\IllegalPostTransition;
use App\Domain\Content\Exceptions\PostActionNotAllowed;
use App\Domain\Content\Exceptions\PostNotPublishable;
use App\Domain\Content\Models\Post;
use App\Domain\Workspaces\CurrentWorkspace;
use App\Domain\Workspaces\Exceptions\CrossWorkspaceWrite;
use App\Domain\Workspaces\Exceptions\MissingWorkspaceContext;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * What the post actions check before they move a post, beyond what the
 * state machine on Post enforces by itself: who is asking, whether the post
 * can actually be sent, and whether the time makes sense.
 */
class PostPreconditions
{
    /** A time this far in the past still counts as "now". */
    public const PAST_GRACE_SECONDS = 60;

    /** Further ahead than this is treated as a mistake. */
    public const MAX_DAYS_AHEAD = 366;

    public function __construct(
        private readonly ChannelManager $channels,
        private readonly CurrentWorkspace $currentWorkspace,
    ) {}

    /**
     * Owners and editors of the post's workspace may act on it, and only
     * from inside that workspace. Reads the post again first, so that the
     * checks which follow see the row as it is now.
     *
     * @throws PostActionNotAllowed
     * @throws CrossWorkspaceWrite when the post is not in the current workspace
     * @throws MissingWorkspaceContext when there is no current workspace
     */
    public function authorize(User $actor, Post $post): void
    {
        $currentId = $this->currentWorkspace->getOrFail()->id;

        if ($post->workspace_id !== $currentId) {
            throw CrossWorkspaceWrite::for($post, $currentId);
        }

        $post->refresh();

        if (! $actor->exists || ! ($post->workspace?->roleOf($actor)?->canEditContent() ?? false)) {
            throw PostActionNotAllowed::for($post, $actor);
        }
    }

    /**
     * @throws IllegalPostTransition when the post is in none of the given states
     */
    public function assertState(Post $post, PostState $to, PostState ...$from): void
    {
        if (! in_array($post->state, $from, true)) {
            throw IllegalPostTransition::between($post, $post->state, $to);
        }
    }

    /**
     * The post has at least one variant, and every variant that is not on
     * its platform yet has a channel that can publish and a text the
     * platform accepts.
     *
     * @throws PostNotPublishable
     */
    public function assertReadyToPublish(Post $post): void
    {
        $errors = $this->problems($post);

        if ($errors !== []) {
            throw new PostNotPublishable($post, $errors);
        }
    }

    /**
     * The same check without the exception, keyed like validation errors.
     *
     * @return array<string, non-empty-list<string>>
     */
    public function problems(Post $post): array
    {
        // A list that has loaded the variants for many posts at once does
        // not pay for a query per post here.
        $variants = $post->relationLoaded('variants')
            ? $post->variants->loadMissing('channel')->sortBy('id')->values()
            : $post->variants()->with('channel')->orderBy('id')->get();

        if ($variants->isEmpty()) {
            return ['variants' => [self::text('The post has no text for any channel. Add at least one channel.')]];
        }

        $errors = [];

        foreach ($variants as $variant) {
            if ($variant->isPublished()) {
                continue;
            }

            $channel = $variant->channel;
            $problem = self::channelProblem($channel);

            if ($channel === null || $problem !== null) {
                $errors["variants.{$variant->id}.channel"] = [$problem ?? self::text('The channel no longer exists.')];

                continue;
            }

            try {
                $capabilities = $this->channels->for($channel)->capabilities();
            } catch (ChannelAdapterNotAvailable) {
                $errors["variants.{$variant->id}.channel"] = [self::platformUnavailable($channel)];

                continue;
            }

            if (trim($variant->body) === '') {
                $errors["variants.{$variant->id}.body"] = [self::text('The text for :channel is empty.', ['channel' => $channel->display_name])];
            } elseif (! $capabilities->fits($variant->body)) {
                $errors["variants.{$variant->id}.body"] = [self::text('The text for :channel is :length characters long. The limit is :limit.', [
                    'channel' => $channel->display_name,
                    'length' => mb_strlen($variant->body),
                    'limit' => $capabilities->maxBodyLength,
                ])];
            }
        }

        return $errors;
    }

    /**
     * The time is not in the past (with a minute of grace, so that "now" is
     * accepted) and not more than a year ahead.
     *
     * @throws PostNotPublishable
     */
    public function assertSensibleTime(Post $post, DateTimeInterface $at): void
    {
        $at = CarbonImmutable::instance($at);

        if ($at->lt(now()->subSeconds(self::PAST_GRACE_SECONDS))) {
            throw new PostNotPublishable($post, ['scheduled_at' => [self::text('The scheduled time has passed. Choose a time in the future.')]]);
        }

        if ($at->gt(now()->addDays(self::MAX_DAYS_AHEAD))) {
            throw new PostNotPublishable($post, ['scheduled_at' => [self::text('The scheduled time is more than a year ahead.')]]);
        }
    }

    /**
     * Why the channel cannot publish, in words for the user, or null when
     * it can. PublishPostJob stores the same text on a variant it has to
     * skip.
     */
    public static function channelProblem(?Channel $channel): ?string
    {
        if ($channel === null) {
            return self::text('The channel no longer exists.');
        }

        if ($channel->canPublish()) {
            return null;
        }

        $name = ['channel' => $channel->display_name];

        return match (true) {
            $channel->status === ChannelStatus::NeedsReconnect => self::text(':channel must be connected again before it can publish.', $name),
            $channel->status === ChannelStatus::Disconnected => self::text(':channel is disconnected.', $name),
            $channel->tokenExpired() => self::text('The access to :channel has expired. Connect it again.', $name),
            default => self::text(':channel cannot publish right now.', $name),
        };
    }

    /**
     * The text for a channel whose platform has no adapter on this instance.
     */
    public static function platformUnavailable(Channel $channel): string
    {
        return self::text('Publishing to :platform is not available on this instance.', ['platform' => $channel->platform]);
    }

    /**
     * Every text a user reads goes through the translator, as in the
     * controllers.
     *
     * @param  array<string, string|int>  $replace
     */
    private static function text(string $key, array $replace = []): string
    {
        $text = __($key, $replace);

        return is_string($text) ? $text : $key;
    }
}
