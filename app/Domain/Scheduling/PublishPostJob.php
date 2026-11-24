<?php

namespace App\Domain\Scheduling;

use App\Domain\Channels\ChannelManager;
use App\Domain\Channels\Contracts\PublishResult;
use App\Domain\Channels\Exceptions\ChannelAdapterNotAvailable;
use App\Domain\Channels\Exceptions\ChannelException;
use App\Domain\Channels\Models\Channel;
use App\Domain\Content\Exceptions\IllegalPostTransition;
use App\Domain\Content\Exceptions\VariantAlreadyPublished;
use App\Domain\Content\Models\Post;
use App\Domain\Content\Models\PostVariant;
use App\Domain\Content\PostPreconditions;
use App\Domain\Content\PostState;
use App\Domain\Scheduling\Events\PostFailedToPublish;
use App\Domain\Workspaces\CurrentWorkspace;
use App\Domain\Workspaces\Models\Workspace;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends the variants of one claimed post to their platforms. One job per
 * post and per round of publishing (one claim by the scheduler).
 *
 * A duplicate public post is the worst failure this system can have, so:
 *
 * - The job only acts on a post that is in the publishing state, in the
 *   round it was queued for, with an approval on the row. It cannot approve,
 *   schedule or claim anything.
 * - Each platform call is made while holding the lock of that variant, after
 *   reading the variant again inside the lock. A variant with an external id
 *   is never sent.
 * - The queue never runs this job a second time by itself ($tries is 1). A
 *   run that was cut off may have reached the platform, so it is not
 *   repeated; SettleInterruptedPost fails the post and a human decides.
 * - A retry happens only after the adapter answered with a failure it marks
 *   as retryable. The job then queues its own successor, which carries the
 *   attempt number and the variants that are already given up.
 *
 * Variants that fail for good do not stop the others. When no variant will
 * be tried again, the post is finished as published or failed.
 */
final class PublishPostJob implements ShouldQueue
{
    use Queueable;

    public const UNKNOWN_OUTCOME = 'Publishing stopped with an unexpected error, and it is not known whether the post reached the channel. Check the channel before you try again.';

    /** Seconds on top of the platform calls for the job's own work. */
    private const SLACK_SECONDS = 15;

    /** The queue must never run this job again by itself. See the class comment. */
    public int $tries = 1;

    public int $timeout;

    public bool $failOnTimeout = true;

    /**
     * @param  int  $round  identifies the claim this job belongs to, see roundOf()
     * @param  int  $attempt  which attempt of this round this is, from 1
     * @param  list<int>  $givenUp  ids of variants that failed for good earlier in this round
     * @param  int  $calls  how many platform calls this run can make at most, for the time limit
     */
    public function __construct(
        public int $workspaceId,
        public int $postId,
        public int $round,
        public int $attempt = 1,
        public array $givenUp = [],
        int $calls = 1,
    ) {
        $this->timeout = PublishingConfig::timeout() * max(1, $calls) + self::SLACK_SECONDS;
        $this->onQueue(PublishingConfig::queue());
    }

    /**
     * The job for a post the scheduler has just claimed.
     */
    public static function forClaimed(Post $post): self
    {
        return new self(
            workspaceId: $post->workspace_id,
            postId: $post->id,
            round: self::roundOf($post),
            calls: $post->variants()->unpublished()->count(),
        );
    }

    /**
     * A post can be published in several rounds: the first, and one for each
     * time a human retries it. Post::retry() gives every round a new
     * scheduled_at, and that time cannot change while the post is scheduled
     * or publishing, so it tells a job from an earlier round apart.
     */
    public static function roundOf(Post $post): int
    {
        return (int) $post->scheduled_at?->getTimestamp();
    }

    public function handle(CurrentWorkspace $currentWorkspace, ChannelManager $channels): void
    {
        $workspace = Workspace::query()->find($this->workspaceId);

        if ($workspace === null) {
            return;
        }

        $currentWorkspace->run($workspace, function () use ($channels): void {
            $this->publish($channels);
        });
    }

    /**
     * Called by the queue when the job threw or ran out of time. Nothing
     * will run it again, so the post is settled here if that is safe.
     */
    public function failed(?Throwable $exception): void
    {
        // The queue delivered the job a second time. The first delivery may
        // still be running, so leave the post to it or to the recovery
        // command.
        if ($exception instanceof MaxAttemptsExceededException) {
            return;
        }

        try {
            $workspace = Workspace::query()->find($this->workspaceId);

            if ($workspace === null) {
                return;
            }

            app(CurrentWorkspace::class)->run($workspace, function (): void {
                $post = $this->postInThisRound();

                if ($post !== null) {
                    app(SettleInterruptedPost::class)->handle($post);
                }
            });
        } catch (Throwable $e) {
            Log::error('A failed publishing job could not settle its post. The recovery command will.', [
                'workspace_id' => $this->workspaceId,
                'post_id' => $this->postId,
                'exception' => $e::class,
            ]);
        }
    }

    private function publish(ChannelManager $channels): void
    {
        $post = $this->postInThisRound();

        if ($post === null) {
            return;
        }

        // Sign of life for the recovery of stuck posts.
        Post::query()->whereKey($post->id)->where('state', PostState::Publishing)->touch();

        $givenUp = $this->givenUp;
        $retryIn = null;
        $retrying = 0;

        foreach ($post->variants()->unpublished()->orderBy('id')->pluck('id') as $variantId) {
            $variantId = (int) $variantId;

            if (in_array($variantId, $givenUp, true)) {
                continue;
            }

            [$outcome, $wait] = $this->publishVariant($variantId, $channels);

            if ($outcome === AttemptOutcome::NotOurs) {
                return;
            }

            if ($outcome === AttemptOutcome::Failed) {
                $givenUp[] = $variantId;
            }

            if ($outcome === AttemptOutcome::RetryLater) {
                $retryIn = max($retryIn ?? 0, $wait);
                $retrying++;
            }
        }

        if ($retryIn !== null) {
            dispatch(new self($this->workspaceId, $this->postId, $this->round, $this->attempt + 1, $givenUp, $retrying))
                ->delay($retryIn);

            return;
        }

        try {
            $state = $post->finishPublishing();
        } catch (IllegalPostTransition) {
            // Settled by the recovery in the meantime.
            return;
        }

        if ($state === PostState::Failed) {
            event(new PostFailedToPublish($post->workspace_id, $post->id));
        }
    }

    /**
     * Makes at most one platform call for the variant, inside its lock.
     *
     * @return array{AttemptOutcome, int} the outcome and, for RetryLater, the seconds to wait
     */
    private function publishVariant(int $variantId, ChannelManager $channels): array
    {
        // The lock outlives the job's own time limit, so it cannot expire
        // while this process is still inside the platform call.
        $lock = VariantLock::for($variantId, max(PublishingConfig::lockSeconds(), $this->timeout + 30));

        if (! $lock->get()) {
            return [AttemptOutcome::NotOurs, 0];
        }

        $recorded = true;

        try {
            if ($this->postInThisRound() === null) {
                return [AttemptOutcome::NotOurs, 0];
            }

            $variant = PostVariant::query()->find($variantId);

            if ($variant === null || $variant->isPublished()) {
                return [AttemptOutcome::Settled, 0];
            }

            $channel = Channel::query()->find($variant->channel_id);
            $problem = PostPreconditions::channelProblem($channel);

            if ($channel === null || $problem !== null) {
                $variant->markFailed($problem ?? 'The channel no longer exists.');

                return [AttemptOutcome::Failed, 0];
            }

            try {
                $adapter = $channels->for($channel);
            } catch (ChannelAdapterNotAvailable) {
                $variant->markFailed("Publishing to {$channel->platform} is not available on this instance.");

                return [AttemptOutcome::Failed, 0];
            }

            try {
                $result = $adapter->publish($variant);
            } catch (ChannelException $e) {
                return $this->afterFailure($variant, $channel, $e);
            } catch (Throwable $e) {
                // Not an answer from the platform. The exception itself is
                // not logged: HTTP client exceptions can carry tokens.
                Log::error('A channel adapter threw an unexpected exception while publishing.', [
                    'workspace_id' => $this->workspaceId,
                    'post_id' => $this->postId,
                    'variant_id' => $variantId,
                    'exception' => $e::class,
                ]);

                $variant->markFailed(self::UNKNOWN_OUTCOME);

                return [AttemptOutcome::Failed, 0];
            }

            // The platform has the post. Nothing may come between the call
            // and writing its result down.
            $recorded = false;
            $this->record($variant, $result);
            $recorded = true;

            return [AttemptOutcome::Settled, 0];
        } finally {
            // A result that could not be written keeps its lock until it
            // expires, so that nothing sends the variant again meanwhile.
            if ($recorded) {
                $lock->release();
            }
        }
    }

    private function record(PostVariant $variant, PublishResult $result): void
    {
        try {
            retry(
                [200, 1000, 3000],
                fn () => $variant->markPublished($result),
                when: fn (Throwable $e): bool => ! $e instanceof VariantAlreadyPublished,
            );
        } catch (VariantAlreadyPublished) {
            $stored = PostVariant::query()->find($variant->id);

            if ($stored?->external_id !== $result->externalId) {
                $this->logUnrecorded($variant, $result);
            }
        } catch (Throwable $e) {
            $this->logUnrecorded($variant, $result);

            throw $e;
        }
    }

    /**
     * The one case where the platform has a post that the database does not
     * know. The log line holds what is needed to repair it by hand.
     */
    private function logUnrecorded(PostVariant $variant, PublishResult $result): void
    {
        Log::critical('A variant was published but its result could not be recorded. Do not retry the post before this is repaired.', [
            'workspace_id' => $this->workspaceId,
            'post_id' => $this->postId,
            'variant_id' => $variant->id,
            'external_id' => $result->externalId,
            'permalink' => $result->permalink,
            'published_at' => $result->publishedAt->toIso8601String(),
        ]);
    }

    /**
     * @return array{AttemptOutcome, int}
     */
    private function afterFailure(PostVariant $variant, Channel $channel, ChannelException $e): array
    {
        Log::warning('Publishing a variant failed.', [
            'workspace_id' => $this->workspaceId,
            'post_id' => $this->postId,
            'variant_id' => $variant->id,
            'attempt' => $this->attempt,
            'message' => $e->getMessage(),
            ...$e->context(),
        ]);

        if ($e->requiresReconnect) {
            $channel->markNeedsReconnect();
        }

        $message = $e->getMessage();

        if ($e->retryable && ! $e->requiresReconnect && $this->attempt < PublishingConfig::maxAttempts()) {
            $wait = max(PublishingConfig::backoffBefore($this->attempt), $e->retryAfterSeconds ?? 0);

            if ($wait <= PublishingConfig::longestBackoff()) {
                // Shown as the last error while the post waits for its retry.
                $variant->markFailed($message);

                return [AttemptOutcome::RetryLater, $wait];
            }

            $message .= ' The platform asked for a longer wait than this instance retries for. Try again later.';
        }

        $variant->markFailed($message);

        return [AttemptOutcome::Failed, 0];
    }

    /**
     * The post, if it is still what this job was queued for: publishing, in
     * the same round, and approved. Anything else means the job has nothing
     * to do.
     */
    private function postInThisRound(): ?Post
    {
        $post = Post::query()->find($this->postId);

        if ($post === null || $post->state !== PostState::Publishing || self::roundOf($post) !== $this->round) {
            return null;
        }

        // The database refuses such a row. Checked anyway: nothing reaches a
        // platform without an approval.
        if ($post->approved_at === null) {
            return null;
        }

        return $post;
    }
}
