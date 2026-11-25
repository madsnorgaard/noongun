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
 * Sends the variants of one claimed post to their platforms. One chain of
 * jobs per post and per round of publishing (one claim by the scheduler).
 *
 * A duplicate public post is the worst failure this system can have, so:
 *
 * - Every job names a round and a step. Before it does anything it takes
 *   that step on the post row with one conditional UPDATE
 *   (Post::beginPublishingStep()). The database gives each round and step
 *   out once, so a job that is run a second time - by queue:retry, by the
 *   retry button in Horizon, by a queue that delivers twice - does nothing.
 *   The post must also be publishing with an approval for the step to be
 *   given. The job cannot approve, schedule or claim anything.
 * - Each platform call is made while holding the lock of that variant, after
 *   reading the variant again inside the lock. A variant with an external id
 *   is never sent.
 * - Just before the call the variant is marked (PostVariant::beginAttempt()).
 *   The mark is cleared when the result is recorded or the platform
 *   certainly made no post. If the process dies in between, the mark stays:
 *   the outcome is unknown, and that is on the row for good.
 * - A retry happens only after the adapter answered with a failure it marks
 *   as retryable. The job then queues its successor: the next step, the
 *   next attempt, and the variants that are already given up.
 *
 * One run sends at most noongun.publishing.calls_per_job variants and hands
 * the rest to a successor, so the time limit of a job has an upper bound.
 *
 * Variants that fail for good do not stop the others. When no variant will
 * be tried again, the post is finished as published or failed.
 */
final class PublishPostJob implements ShouldQueue
{
    use Queueable;

    public const UNKNOWN_OUTCOME = 'Publishing stopped with an unexpected error, and it is not known whether the post reached the channel. Check the channel before you try again.';

    public const EARLIER_ATTEMPT = 'An earlier attempt may have reached the channel. Check it before you try again.';

    /**
     * The queue must never run this job again by itself. A run by hand is
     * stopped by the step, see the class comment.
     */
    public int $tries = 1;

    public int $timeout;

    public bool $failOnTimeout = true;

    /**
     * @param  int  $round  the claim this job belongs to: posts.publish_round after the claim
     * @param  int  $step  which run of the job in this round this is, from 1; each runs once
     * @param  int  $attempt  how many times the variants that are still open have been sent, plus one
     * @param  list<int>  $givenUp  ids of variants that failed for good earlier in this round
     * @param  int  $calls  how many platform calls this run can make at most, for the time limit
     */
    public function __construct(
        public int $workspaceId,
        public int $postId,
        public int $round,
        public int $step = 1,
        public int $attempt = 1,
        public array $givenUp = [],
        int $calls = 1,
    ) {
        $this->timeout = PublishingConfig::jobSeconds($calls);
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
            round: $post->publish_round,
            calls: $post->variants()->unpublished()->count(),
        );
    }

    public function handle(CurrentWorkspace $currentWorkspace, ChannelManager $channels): void
    {
        // Before anything is read or taken: without a shared lock store
        // nothing may be sent. The step is not used up, so the job can be
        // retried once the store is fixed.
        VariantLock::assertStoreIsShared();

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

                // Only the run that holds the newest step, or the one
                // that was next and never started, may settle. Settling
                // sends nothing, and it ends the round for every job.
                if ($post !== null && in_array($post->publish_step, [$this->step, $this->step - 1], true)) {
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

        // From here on this round and step belong to this run alone, and
        // no other run will ever get them.
        if ($post === null || ! $post->beginPublishingStep($this->round, $this->step)) {
            return;
        }

        $givenUp = $this->givenUp;
        $budget = PublishingConfig::callsPerJob();
        $retryIn = null;
        $open = 0;

        foreach ($post->variants()->unpublished()->orderBy('id')->pluck('id') as $variantId) {
            $variantId = (int) $variantId;

            if (in_array($variantId, $givenUp, true)) {
                continue;
            }

            // Out of calls for this run: the successor takes this one.
            if ($budget === 0) {
                $open++;

                continue;
            }

            $budget--;

            [$outcome, $wait] = $this->publishVariant($variantId, $channels);

            if ($outcome === AttemptOutcome::NotOurs) {
                return;
            }

            if ($outcome === AttemptOutcome::Failed) {
                $givenUp[] = $variantId;
            }

            if ($outcome === AttemptOutcome::RetryLater) {
                $retryIn = max($retryIn ?? 0, $wait);
                $open++;
            }
        }

        if ($open > 0) {
            dispatch(new self(
                workspaceId: $this->workspaceId,
                postId: $this->postId,
                round: $this->round,
                step: $this->step + 1,
                attempt: $retryIn !== null ? $this->attempt + 1 : $this->attempt,
                givenUp: $givenUp,
                calls: $open,
            ))->delay($retryIn ?? 0);

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
        // The lock outlives the time limit of any job, so it cannot expire
        // while this process is still inside the platform call.
        $lock = VariantLock::for($variantId, PublishingConfig::lockSeconds());

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
                $variant->markFailed(PostPreconditions::platformUnavailable($channel));

                return [AttemptOutcome::Failed, 0];
            }

            try {
                // Written down before the call: from here the outcome is
                // unknown until something says otherwise.
                $firstMark = $variant->beginAttempt();
            } catch (VariantAlreadyPublished) {
                return [AttemptOutcome::Settled, 0];
            }

            try {
                $result = $adapter->publish($variant);
            } catch (ChannelException $e) {
                return $this->afterFailure($variant, $channel, $e, $firstMark);
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

    /**
     * Writes the result down. If the first try fails the result is logged
     * at once, before any waiting: the job may run out of time while it
     * waits to try again, and the log line is then the only record.
     */
    private function record(PostVariant $variant, PublishResult $result): void
    {
        try {
            $variant->markPublished($result);

            return;
        } catch (VariantAlreadyPublished) {
            $this->confirmRecorded($variant, $result);

            return;
        } catch (Throwable) {
            $this->logUnrecorded($variant, $result);
        }

        try {
            retry(
                [200, 1000, 3000],
                fn () => $variant->markPublished($result),
                when: fn (Throwable $e): bool => ! $e instanceof VariantAlreadyPublished,
            );
        } catch (VariantAlreadyPublished) {
            $this->confirmRecorded($variant, $result);

            return;
        }

        Log::notice('The result of a published variant was recorded after all. The earlier critical entry needs no action.', [
            'workspace_id' => $this->workspaceId,
            'post_id' => $this->postId,
            'variant_id' => $variant->id,
            'external_id' => $result->externalId,
        ]);
    }

    /**
     * markPublished() found an external id on the row. Fine if it is ours
     * (the write went through but its answer was lost), alarming if not.
     */
    private function confirmRecorded(PostVariant $variant, PublishResult $result): void
    {
        $stored = PostVariant::query()->find($variant->id);

        if ($stored?->external_id !== $result->externalId) {
            $this->logUnrecorded($variant, $result);
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
     * @param  bool  $firstMark  whether this attempt set the mark of an unknown outcome, see PostVariant::beginAttempt()
     * @return array{AttemptOutcome, int}
     */
    private function afterFailure(PostVariant $variant, Channel $channel, ChannelException $e, bool $firstMark): array
    {
        $context = $e->context();

        Log::warning('Publishing a variant failed.', [
            'workspace_id' => $this->workspaceId,
            'post_id' => $this->postId,
            'variant_id' => $variant->id,
            'attempt' => $this->attempt,
            'message' => $e->getMessage(),
            ...$context,
        ]);

        if ($e->requiresReconnect) {
            $channel->markNeedsReconnect();
        }

        $message = $e->getMessage();

        // The adapter does not know whether the platform made the post.
        // The mark stays, and nothing sends this variant again by itself.
        if (($context['outcome_unknown'] ?? false) === true) {
            $variant->markFailed($message);

            return [AttemptOutcome::Failed, 0];
        }

        // From here the platform certainly made no post in this attempt.
        // The mark is cleared only if this attempt set it: one left by an
        // earlier attempt still stands, whatever the platform says now.
        $write = function (string $text) use ($variant, $firstMark): void {
            $firstMark ? $variant->markRefused($text) : $variant->markFailed($text.' '.self::EARLIER_ATTEMPT);
        };

        if ($e->retryable && ! $e->requiresReconnect && $this->attempt < PublishingConfig::maxAttempts()) {
            $wait = max(PublishingConfig::backoffBefore($this->attempt), $e->retryAfterSeconds ?? 0);

            if ($wait <= PublishingConfig::longestBackoff()) {
                // Shown as the last error while the post waits for its retry.
                $write($message);

                return [AttemptOutcome::RetryLater, $wait];
            }

            $message .= ' The platform asked for a longer wait than this instance retries for. Try again later.';
        }

        $write($message);

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

        if ($post === null || $post->state !== PostState::Publishing || $post->publish_round !== $this->round) {
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
