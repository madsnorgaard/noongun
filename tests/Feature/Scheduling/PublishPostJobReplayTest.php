<?php

/*
 * A queued PublishPostJob can be run again by hand: `queue:retry`, the retry
 * button in Horizon, or a queue that delivers twice. Whatever shape the job
 * failed in, a second run of the same payload must not reach a platform.
 */

use App\Domain\Channels\Contracts\PublishResult;
use App\Domain\Channels\Exceptions\PublishFailed;
use App\Domain\Channels\Models\Channel;
use App\Domain\Content\Models\PostVariant;
use App\Domain\Content\PostState;
use App\Domain\Scheduling\PublishPostJob;
use App\Domain\Scheduling\SettleInterruptedPost;
use App\Domain\Scheduling\VariantLock;
use App\Domain\Workspaces\Models\Workspace;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;

require_once __DIR__.'/../../Support/publishing.php';

beforeEach(function () {
    $this->travelTo('2026-11-24 10:00:00');

    config([
        'noongun.publishing.max_attempts' => 3,
        'noongun.publishing.backoff' => [60, 300],
        'noongun.publishing.timeout' => 30,
        'noongun.publishing.calls_per_job' => 3,
    ]);

    $this->fake = fakeChannelAdapter();
    $this->workspace = Workspace::factory()->create();
});

/**
 * The same payload again, as `queue:retry` pushes it: rebuilt from what was
 * stored, with the attempts of the queue reset.
 */
function replay(PublishPostJob $job): void
{
    $copy = unserialize(serialize($job));

    expect($copy)->toBeInstanceOf(PublishPostJob::class);

    runPublishJob($copy);
}

/**
 * Makes the database refuse to record a publish result while the returned
 * switch is on, the way an outage right after the platform call would.
 */
function databaseRefusesResults(): stdClass
{
    $switch = new stdClass;
    $switch->on = true;

    DB::connection()->beforeExecuting(function (string $query) use ($switch): void {
        if ($switch->on && str_starts_with($query, 'update "post_variants"') && str_contains($query, '"permalink"')) {
            throw new RuntimeException('The database went away.');
        }
    });

    return $switch;
}

describe('a job that is run a second time', function () {
    it('does nothing after it published', function () {
        [$post, $job] = claimedPost($this->workspace, 2);

        runPublishJob($job);
        replay($job);
        replay($job);

        expect($this->fake->publishCount())->toBe(2)
            ->and(freshPost($post)->state)->toBe(PostState::Published);
    });

    it('does nothing while the post is still publishing and waits for its retry', function () {
        Queue::fake();
        [$post, $job] = claimedPost($this->workspace, 1);
        $this->fake->failRetryable();

        runPublishJob($job);
        expect(freshPost($post)->state)->toBe(PostState::Publishing);

        // The first job is run again by hand, while its successor waits.
        replay($job);
        replay($job);

        expect($this->fake->publishCount())->toBe(1);
        Queue::assertPushed(PublishPostJob::class, 1);

        $successor = Queue::pushed(PublishPostJob::class)->sole();
        runPublishJob($successor);
        replay($successor);
        replay($job);

        expect($this->fake->publishCount())->toBe(2)
            ->and(freshPost($post)->state)->toBe(PostState::Published);
    });

    it('does not send a variant again that was given up with an unknown outcome', function () {
        Queue::fake();
        $channels = inWorkspace($this->workspace, fn () => Channel::factory()->count(2)->create()->all());
        [$post, $job] = claimedPost($this->workspace, $channels);
        [$inDoubt, $busy] = variantsOf($post);

        $this->fake->publishReturns(function (PostVariant $variant, int $call) use ($inDoubt): PublishResult {
            if ($variant->id === $inDoubt->id) {
                throw new RuntimeException('Connection reset by peer.');
            }

            if ($call === 1) {
                throw PublishFailed::retryable('facebook', 'The platform is busy.');
            }

            return new PublishResult("fb_{$variant->id}_{$call}", null, now());
        });

        runPublishJob($job);

        // The first job again, with its empty list of given-up variants.
        replay($job);

        $this->fake->assertPublishedOnce($inDoubt)->assertPublishedOnce($busy);

        runPublishJob(Queue::pushed(PublishPostJob::class)->sole());
        replay($job);

        $this->fake->assertPublishedOnce($inDoubt)->assertPublishedTimes($busy, 2);
        expect(freshPost($post)->state)->toBe(PostState::Failed)
            ->and(variantsOf($post)[0]->hasUnknownOutcome())->toBeTrue()
            ->and(variantsOf($post)[0]->error)->toBe(PublishPostJob::UNKNOWN_OUTCOME);
    });

    it('does not skip ahead: a step is only given when the one before it was taken', function () {
        [$post, $job] = claimedPost($this->workspace, 1);

        runPublishJob(new PublishPostJob($job->workspaceId, $job->postId, $job->round, step: 2, attempt: 2));
        runPublishJob(new PublishPostJob($job->workspaceId, $job->postId, $job->round, step: 0));

        $this->fake->assertNothingPublished();
        expect(freshPost($post)->publish_step)->toBe(0);

        runPublishJob($job);

        expect($this->fake->publishCount())->toBe(1);
    });

    it('runs once when two workers start the same payload at the same moment', function () {
        [$post, $job] = claimedPost($this->workspace, 1);

        // Both have read the post as publishing. Only one gets the step.
        $first = inWorkspace($this->workspace, fn () => freshPost($post)->beginPublishingStep($job->round, 1));
        $second = inWorkspace($this->workspace, fn () => freshPost($post)->beginPublishingStep($job->round, 1));

        expect([$first, $second])->toBe([true, false]);
    });
});

describe('the database fails right after the platform accepted the post', function () {
    it('logs the result at once, keeps the lock, leaves the post publishing, and never sends again', function () {
        Sleep::fake();
        Log::spy();
        [$post, $job] = claimedPost($this->workspace, 1);
        [$variant] = variantsOf($post);
        $outage = databaseRefusesResults();

        // The critical line must be written before the job waits to try
        // again: its time limit can end it during that wait.
        $loggedBeforeWaiting = null;
        Sleep::whenFakingSleep(function () use (&$loggedBeforeWaiting): void {
            $loggedBeforeWaiting ??= Log::getFacadeRoot()->shouldHaveReceived('critical')->once() !== null;
        });

        expect(fn () => dispatch($job))->toThrow(RuntimeException::class, 'The database went away.');

        expect($loggedBeforeWaiting)->toBeTrue();
        Log::shouldHaveReceived('critical')
            ->withArgs(fn (string $message, array $context) => $context['external_id'] === "facebook_{$variant->id}_1"
                && $context['variant_id'] === $variant->id
                && $context['permalink'] === "https://platform.test/facebook/posts/facebook_{$variant->id}_1")
            ->once();

        $this->fake->assertPublishedOnce($variant);

        [$stored] = variantsOf($post);

        expect($stored->isPublished())->toBeFalse()
            ->and($stored->hasUnknownOutcome())->toBeTrue()
            ->and(freshPost($post)->state)->toBe(PostState::Publishing)
            // The failure hook could not settle the post: the lock is kept.
            ->and(VariantLock::for($variant->id, 5)->get())->toBeFalse();

        // The outage ends. The operator runs `queue:retry all`.
        $outage->on = false;
        replay($job);
        dispatch(unserialize(serialize($job)));

        $this->fake->assertPublishedOnce($variant);

        // Much later the lock has expired and someone retries once more.
        Cache::lock(VariantLock::key($variant->id))->forceRelease();
        replay($job);

        $this->fake->assertPublishedOnce($variant);
        expect(freshPost($post)->state)->toBe(PostState::Publishing);

        // The recovery ends the round and says what is in doubt.
        $this->travel(2)->hours();
        $this->artisan('noongun:recover-stuck-posts')->assertSuccessful();
        replay($job);

        [$stored] = variantsOf($post);

        $this->fake->assertPublishedOnce($variant);
        expect(freshPost($post)->state)->toBe(PostState::Failed)
            ->and($stored->error)->toBe(SettleInterruptedPost::MESSAGE)
            ->and($stored->hasUnknownOutcome())->toBeTrue();
    });

    it('records the result when the database is back within the retries, and says so', function () {
        Sleep::fake();
        Log::spy();
        [$post, $job] = claimedPost($this->workspace, 1);
        $outage = databaseRefusesResults();

        Sleep::whenFakingSleep(function () use ($outage): void {
            $outage->on = false;
        });

        runPublishJob($job);

        [$variant] = variantsOf($post);

        expect($variant->external_id)->toBe("facebook_{$variant->id}_1")
            ->and($variant->hasUnknownOutcome())->toBeFalse()
            ->and(freshPost($post)->state)->toBe(PostState::Published);

        Log::shouldHaveReceived('critical')->once();
        Log::shouldHaveReceived('notice')->once();
    });
});

describe('a job that ran out of time inside a platform call', function () {
    it('cannot settle the post from its failure hook, because the variant is still locked', function () {
        [$post, $job] = claimedPost($this->workspace, 2);
        [$first, $second] = variantsOf($post);
        $during = null;

        $this->fake->publishReturns(function (PostVariant $variant, int $call) use ($job, $post, $first, &$during): PublishResult {
            if ($variant->id === $first->id) {
                // The worker's alarm fires here: the queue calls failed()
                // on the job and then kills the process.
                $job->failed(new TimeoutExceededException('The job has timed out.'));

                $during = [
                    'state' => freshPost($post)->state,
                    'error' => variantsOf($post)[0]->error,
                    'unknown' => variantsOf($post)[0]->hasUnknownOutcome(),
                    'other' => variantsOf($post)[1]->error,
                ];
            }

            return new PublishResult("fb_{$variant->id}_{$call}", null, now());
        });

        runPublishJob($job);

        // Nothing was settled or marked as failed while the call was open,
        // and the mark that the outcome is unknown was already on the row.
        expect($during)->toBe(['state' => PostState::Publishing, 'error' => null, 'unknown' => true, 'other' => null]);

        $this->fake->assertPublishedOnce($first)->assertPublishedOnce($second);
    });

    it('is not sent again by hand, and the recovery marks exactly the variant that is in doubt', function () {
        [$post, $job] = claimedPost($this->workspace, 2);
        [$inDoubt, $neverTried] = variantsOf($post);

        // What a killed worker leaves behind: the step taken, the first
        // variant marked and locked, nothing recorded.
        inWorkspace($this->workspace, function () use ($post, $job, $inDoubt) {
            expect(freshPost($post)->beginPublishingStep($job->round, 1))->toBeTrue();
            PostVariant::query()->findOrFail($inDoubt->id)->beginAttempt();
        });
        $dead = VariantLock::for($inDoubt->id, 135);
        expect($dead->get())->toBeTrue();

        // The queue hands the job out again and gives up on it.
        $job->failed(new MaxAttemptsExceededException('Attempted too many times.'));
        expect(freshPost($post)->state)->toBe(PostState::Publishing);

        // The operator retries it, before and after the lock has expired.
        replay($job);
        $this->travel(10)->minutes();
        replay($job);

        $this->fake->assertNothingPublished();

        $this->travel(2)->hours();
        $this->artisan('noongun:recover-stuck-posts')->assertSuccessful();
        replay($job);

        [$inDoubt, $neverTried] = variantsOf($post);

        $this->fake->assertNothingPublished();
        expect(freshPost($post)->state)->toBe(PostState::Failed)
            ->and($inDoubt->error)->toBe(SettleInterruptedPost::MESSAGE)
            ->and($inDoubt->hasUnknownOutcome())->toBeTrue()
            ->and($neverTried->error)->toBe(SettleInterruptedPost::NOT_SENT)
            ->and($neverTried->hasUnknownOutcome())->toBeFalse();
    });
});

describe('rounds', function () {
    it('tells two rounds apart even when they have the same scheduled time', function () {
        [$post, $firstRound] = claimedPost($this->workspace, 1);
        $scheduledAt = freshPost($post)->scheduled_at;
        $this->fake->failPermanently();
        runPublishJob($firstRound);

        $secondRound = inWorkspace($this->workspace, function () use ($post, $scheduledAt) {
            $post->refresh()->retry($scheduledAt);
            expect($post->claimForPublishing())->toBeTrue();

            return PublishPostJob::forClaimed($post);
        });

        expect(freshPost($post)->scheduled_at->equalTo($scheduledAt))->toBeTrue()
            ->and([$firstRound->round, $secondRound->round])->toBe([1, 2]);

        // Every shape of job from the first round is dead.
        replay($firstRound);
        foreach ([1, 2, 3] as $step) {
            runPublishJob(new PublishPostJob($firstRound->workspaceId, $firstRound->postId, 1, step: $step, attempt: $step));
        }

        expect($this->fake->publishCount())->toBe(1)
            ->and(freshPost($post)->state)->toBe(PostState::Publishing)
            ->and(freshPost($post)->publish_step)->toBe(0);

        runPublishJob($secondRound);
        replay($secondRound);
        replay($firstRound);

        expect($this->fake->publishCount())->toBe(2)
            ->and(freshPost($post)->state)->toBe(PostState::Published);
    });
});

describe('the mark of an unknown outcome', function () {
    it('is on the row while the platform is being called', function () {
        [$post, $job] = claimedPost($this->workspace, 1);
        $during = null;

        $this->fake->publishReturns(function (PostVariant $variant, int $call) use (&$during): PublishResult {
            $during = DB::table('post_variants')->where('id', $variant->id)->value('outcome_unknown_since');

            return new PublishResult("fb_{$variant->id}_{$call}", null, now());
        });

        runPublishJob($job);

        expect($during)->toBe('2026-11-24 10:00:00')
            ->and(variantsOf($post)[0]->outcome_unknown_since)->toBeNull();
    });

    it('is cleared when the platform certainly made no post', function (Closure $fail) {
        Queue::fake();
        [$post, $job] = claimedPost($this->workspace, 1);
        $fail($this->fake);

        runPublishJob($job);

        expect(variantsOf($post)[0]->hasUnknownOutcome())->toBeFalse()
            ->and(variantsOf($post)[0]->error)->not->toBeNull();
    })->with([
        'a refusal' => [fn ($fake) => $fake->failPermanently()],
        'a rate limit' => [fn ($fake) => $fake->failRetryable()],
        'a token that was refused' => [fn ($fake) => $fake->failReconnectRequired()],
    ]);

    it('stays when the adapter says the outcome is unknown, and nothing is retried', function (bool $retryable) {
        Queue::fake();
        [$post, $job] = claimedPost($this->workspace, 1);
        $this->fake->failNextPublishes(new PublishFailed(
            'facebook',
            'Facebook did not answer in time. The outcome is unknown.',
            retryable: $retryable,
            context: ['outcome_unknown' => true],
        ));

        runPublishJob($job);

        [$variant] = variantsOf($post);

        Queue::assertNothingPushed();
        $this->fake->assertPublishedOnce($variant);
        expect($variant->hasUnknownOutcome())->toBeTrue()
            ->and($variant->error)->toBe('Facebook did not answer in time. The outcome is unknown.')
            ->and(freshPost($post)->state)->toBe(PostState::Failed);
    })->with(['as the contract says, not retryable' => [false], 'even if it is wrongly marked retryable' => [true]]);

    it('is not cleared by a later refusal: only a recorded publish settles an earlier doubt', function () {
        [$post, $job] = claimedPost($this->workspace, 1);
        DB::table('post_variants')->where('post_id', $post->id)->update(['outcome_unknown_since' => '2026-11-23 09:00:00']);
        $this->fake->failPermanently(message: 'Duplicate status message.');

        runPublishJob($job);

        [$variant] = variantsOf($post);

        expect($variant->hasUnknownOutcome())->toBeTrue()
            ->and($variant->outcome_unknown_since->toDateTimeString())->toBe('2026-11-23 09:00:00')
            ->and($variant->error)->toBe('Duplicate status message. '.PublishPostJob::EARLIER_ATTEMPT);
    });

    it('is cleared when a later attempt is recorded as published', function () {
        [$post, $job] = claimedPost($this->workspace, 1);
        DB::table('post_variants')->where('post_id', $post->id)->update(['outcome_unknown_since' => '2026-11-23 09:00:00']);

        runPublishJob($job);

        expect(variantsOf($post)[0]->isPublished())->toBeTrue()
            ->and(variantsOf($post)[0]->outcome_unknown_since)->toBeNull();
    });

    it('is not set when the platform was never called', function () {
        $channel = inWorkspace($this->workspace, fn () => Channel::factory()->create());
        [$post, $job] = claimedPost($this->workspace, [$channel]);
        inWorkspace($this->workspace, fn () => $channel->disconnect());

        runPublishJob($job);

        expect(variantsOf($post)[0]->hasUnknownOutcome())->toBeFalse()
            ->and(freshPost($post)->state)->toBe(PostState::Failed);
    });
});

describe('a post with more variants than one job sends', function () {
    beforeEach(function () {
        config(['noongun.publishing.calls_per_job' => 2]);
    });

    it('hands the rest to a successor without waiting, and sends every variant once', function () {
        Queue::fake();
        [$post, $job] = claimedPost($this->workspace, 5);

        expect($job->timeout)->toBe(75);

        runPublishJob($job);

        $second = Queue::pushed(PublishPostJob::class)->sole();

        expect($this->fake->publishCount())->toBe(2)
            ->and(freshPost($post)->state)->toBe(PostState::Publishing)
            ->and([$second->step, $second->attempt, $second->delay, $second->timeout])->toBe([2, 1, 0, 75]);

        runPublishJob($second);
        $third = Queue::pushed(PublishPostJob::class)->last();

        expect([$third->step, $third->attempt, $third->delay, $third->timeout])->toBe([3, 1, 0, 45]);

        runPublishJob($third);
        replay($job);
        replay($second);
        replay($third);

        expect(Queue::pushed(PublishPostJob::class))->toHaveCount(2)
            ->and($this->fake->publishCount())->toBe(5)
            ->and(freshPost($post)->state)->toBe(PostState::Published);

        foreach (variantsOf($post) as $variant) {
            $this->fake->assertPublishedOnce($variant);
        }
    });

    it('carries a retry and the variants it did not reach in the same successor', function () {
        Queue::fake();
        $channels = inWorkspace($this->workspace, fn () => Channel::factory()->count(3)->create()->all());
        [$post, $job] = claimedPost($this->workspace, $channels);
        [$busy, $ok, $unreached] = variantsOf($post);

        $this->fake->publishReturns(function (PostVariant $variant, int $call) use ($busy): PublishResult {
            if ($variant->id === $busy->id && $call === 1) {
                throw PublishFailed::retryable('facebook', 'The platform is busy.');
            }

            return new PublishResult("fb_{$variant->id}_{$call}", null, now());
        });

        runPublishJob($job);

        $successor = Queue::pushed(PublishPostJob::class)->sole();

        $this->fake->assertNotPublished($unreached);
        expect([$successor->step, $successor->attempt, $successor->delay, $successor->timeout])->toBe([2, 2, 60, 75]);

        runPublishJob($successor);

        $this->fake->assertPublishedTimes($busy, 2)->assertPublishedOnce($ok)->assertPublishedOnce($unreached);
        expect(freshPost($post)->state)->toBe(PostState::Published);
    });

    it('never has a time limit above the bound, however many variants there are', function () {
        [, $job] = claimedPost($this->workspace, 9);

        expect($job->timeout)->toBe(75);
    });
});
