<?php

use App\Domain\Channels\ChannelManager;
use App\Domain\Channels\ChannelStatus;
use App\Domain\Channels\Contracts\PublishResult;
use App\Domain\Channels\Exceptions\PublishFailed;
use App\Domain\Channels\Models\Channel;
use App\Domain\Content\Actions\RetryPost;
use App\Domain\Content\Models\PostVariant;
use App\Domain\Content\PostState;
use App\Domain\Scheduling\Events\PostFailedToPublish;
use App\Domain\Scheduling\PublishPostJob;
use App\Domain\Scheduling\SettleInterruptedPost;
use App\Domain\Scheduling\VariantLock;
use App\Domain\Workspaces\CurrentWorkspace;
use App\Domain\Workspaces\Models\Workspace;
use App\Domain\Workspaces\Role;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/../../Support/publishing.php';

beforeEach(function () {
    $this->travelTo('2026-11-24 10:00:00');

    config([
        'noongun.publishing.queue' => 'publishing',
        'noongun.publishing.max_attempts' => 3,
        'noongun.publishing.backoff' => [60, 300],
        'noongun.publishing.timeout' => 30,
        'noongun.publishing.lock_seconds' => 120,
    ]);

    $this->fake = fakeChannelAdapter();
    $this->workspace = Workspace::factory()->create();
});

/**
 * A publish result that fails for the given variant only.
 */
function failingOnlyFor(PostVariant $failing, PublishFailed $failure): Closure
{
    return function (PostVariant $variant, int $call) use ($failing, $failure): PublishResult {
        if ($variant->id === $failing->id) {
            throw $failure;
        }

        return new PublishResult("fb_{$variant->id}_{$call}", "https://platform.test/posts/fb_{$variant->id}_{$call}", now());
    };
}

it('publishes every variant once and finishes the post as published', function () {
    Event::fake([PostFailedToPublish::class]);
    [$post, $job] = claimedPost($this->workspace, 2);

    runPublishJob($job);

    [$first, $second] = variantsOf($post);

    expect(freshPost($post)->state)->toBe(PostState::Published)
        ->and($first->external_id)->toBe("facebook_{$first->id}_1")
        ->and($first->permalink)->toBe("https://platform.test/facebook/posts/facebook_{$first->id}_1")
        ->and($first->published_at?->toDateTimeString())->toBe('2026-11-24 10:00:00')
        ->and($first->error)->toBeNull()
        ->and($second->isPublished())->toBeTrue();

    $this->fake->assertPublishedOnce($first)->assertPublishedOnce($second);
    Event::assertNotDispatched(PostFailedToPublish::class);
});

it('runs on the configured queue, is never run twice by the queue, and has a time limit per platform call', function () {
    [, $one] = claimedPost($this->workspace, 1);
    [, $three] = claimedPost($this->workspace, 3);

    expect($one->queue)->toBe('publishing')
        ->and($one->tries)->toBe(1)
        ->and($one->failOnTimeout)->toBeTrue()
        ->and($one->attempt)->toBe(1)
        ->and($one->timeout)->toBe(45)
        ->and($three->timeout)->toBe(105);
});

it('survives the trip through the queue with everything it needs', function () {
    [, $job] = claimedPost($this->workspace, 2);
    $successor = (new PublishPostJob($job->workspaceId, $job->postId, $job->round, attempt: 2, givenUp: [7], calls: 2))->delay(60);

    $restored = unserialize(serialize($successor));

    expect($restored)->toBeInstanceOf(PublishPostJob::class)
        ->and([$restored->workspaceId, $restored->postId, $restored->round, $restored->attempt, $restored->givenUp])
        ->toBe([$job->workspaceId, $job->postId, $job->round, 2, [7]])
        ->and($restored->timeout)->toBe(75)
        ->and($restored->tries)->toBe(1)
        ->and($restored->queue)->toBe('publishing')
        ->and($restored->delay)->toBe(60);
});

describe('a duplicate public post never happens', function () {
    it('makes one platform call per variant when the same job is dispatched twice', function () {
        [$post, $job] = claimedPost($this->workspace, 2);

        dispatch(clone $job);
        dispatch(clone $job);

        [$first, $second] = variantsOf($post);

        $this->fake->assertPublishedOnce($first)->assertPublishedOnce($second);
        expect($this->fake->publishCount())->toBe(2)
            ->and(freshPost($post)->state)->toBe(PostState::Published)
            ->and($first->external_id)->toBe("facebook_{$first->id}_1");
    });

    it('makes one platform call when a second worker runs the job while the first is inside the call', function () {
        [$post, $job] = claimedPost($this->workspace, 1);
        $raced = false;

        $this->fake->publishReturns(function (PostVariant $variant, int $call) use ($job, &$raced): PublishResult {
            if (! $raced) {
                $raced = true;

                // The first worker holds the lock and is waiting for the
                // platform. A second worker starts the same job now.
                runPublishJob(clone $job);
            }

            return new PublishResult("fb_{$variant->id}_{$call}", null, now());
        });

        runPublishJob($job);

        [$variant] = variantsOf($post);

        expect($raced)->toBeTrue()
            ->and($variant->external_id)->toBe("fb_{$variant->id}_1")
            ->and(freshPost($post)->state)->toBe(PostState::Published);

        $this->fake->assertPublishedOnce($variant);
    });

    it('does not call the platform or finish the post while another worker holds the variant', function () {
        [$post, $job] = claimedPost($this->workspace, 2);
        [$first, $second] = variantsOf($post);

        $held = VariantLock::for($first->id, 60);
        expect($held->get())->toBeTrue();

        runPublishJob($job);

        $this->fake->assertNothingPublished();
        expect(freshPost($post)->state)->toBe(PostState::Publishing)
            ->and(variantsOf($post)[1]->error)->toBeNull();

        $held->release();
        runPublishJob($job);

        $this->fake->assertPublishedOnce($first)->assertPublishedOnce($second);
        expect(freshPost($post)->state)->toBe(PostState::Published);
    });

    it('releases the lock of a variant when it is done with it', function () {
        [$post, $job] = claimedPost($this->workspace, 1);

        runPublishJob($job);

        $lock = VariantLock::for(variantsOf($post)[0]->id, 5);

        expect($lock->get())->toBeTrue();
        $lock->release();
    });

    it('never sends a variant that got an external id after the job was queued', function () {
        [$post, $job] = claimedPost($this->workspace, 2);
        [$first, $second] = variantsOf($post);

        DB::table('post_variants')->where('id', $first->id)->update(['external_id' => 'already_there', 'published_at' => now()]);

        runPublishJob($job);

        $this->fake->assertNotPublished($first)->assertPublishedOnce($second);
        expect(variantsOf($post)[0]->external_id)->toBe('already_there')
            ->and(freshPost($post)->state)->toBe(PostState::Published);
    });

    it('checks the external id inside the lock, not only when the job starts', function () {
        [$post, $job] = claimedPost($this->workspace, 2);
        [$first, $second] = variantsOf($post);

        // While the first variant is being sent, something else records a
        // result for the second one.
        $this->fake->publishReturns(function (PostVariant $variant, int $call) use ($second): PublishResult {
            DB::table('post_variants')->where('id', $second->id)->whereNull('external_id')
                ->update(['external_id' => 'recorded_elsewhere', 'published_at' => now()]);

            return new PublishResult("fb_{$variant->id}_{$call}", null, now());
        });

        runPublishJob($job);

        $this->fake->assertPublishedOnce($first)->assertNotPublished($second);
        expect(variantsOf($post)[1]->external_id)->toBe('recorded_elsewhere');
    });

    it('does nothing when it runs again after the post is finished', function (string $failure) {
        [$post, $job] = claimedPost($this->workspace, 1);

        if ($failure === 'failed') {
            $this->fake->failPermanently();
        }

        runPublishJob($job);
        runPublishJob($job);
        runPublishJob(clone $job);

        expect($this->fake->publishCount())->toBe(1);
    })->with(['published', 'failed']);

    it('ignores a job from an earlier round of the same post', function () {
        [$post, $stale] = claimedPost($this->workspace, 1);
        $this->fake->failPermanently();
        runPublishJob($stale);
        expect(freshPost($post)->state)->toBe(PostState::Failed);

        // A human retries a minute later; the scheduler claims the post again.
        $this->travel(1)->minutes();
        $editor = userWithRole($this->workspace, Role::Editor);
        $current = inWorkspace($this->workspace, function () use ($post, $editor) {
            app(RetryPost::class)->handle($editor, $post);
            $post->claimForPublishing();

            return PublishPostJob::forClaimed($post);
        });

        expect($current->round)->not->toBe($stale->round);

        // A late copy of the first job must not act on the new round.
        runPublishJob(new PublishPostJob($stale->workspaceId, $stale->postId, $stale->round, attempt: 3, givenUp: [variantsOf($post)[0]->id]));

        expect($this->fake->publishCount())->toBe(1)
            ->and(freshPost($post)->state)->toBe(PostState::Publishing);

        runPublishJob($current);

        expect($this->fake->publishCount())->toBe(2)
            ->and(freshPost($post)->state)->toBe(PostState::Published);
    });
});

describe('retryable failures', function () {
    it('queues its own successor with the configured backoff and keeps the post in publishing', function () {
        Queue::fake();
        Event::fake([PostFailedToPublish::class]);
        [$post, $job] = claimedPost($this->workspace, 1);
        $this->fake->failRetryable(times: 2);

        runPublishJob($job);

        expect(freshPost($post)->state)->toBe(PostState::Publishing)
            ->and(variantsOf($post)[0]->error)->toBe('The platform is busy.');

        Queue::assertPushedOn('publishing', PublishPostJob::class);
        $second = Queue::pushed(PublishPostJob::class)->sole();

        expect($second->attempt)->toBe(2)
            ->and($second->delay)->toBe(60)
            ->and($second->round)->toBe($job->round)
            ->and($second->tries)->toBe(1);

        runPublishJob($second);

        $third = Queue::pushed(PublishPostJob::class)->last();

        expect($third->attempt)->toBe(3)
            ->and($third->delay)->toBe(300);

        runPublishJob($third);

        [$variant] = variantsOf($post);

        expect(Queue::pushed(PublishPostJob::class))->toHaveCount(2)
            ->and(freshPost($post)->state)->toBe(PostState::Published)
            ->and($variant->external_id)->toBe("facebook_{$variant->id}_3")
            ->and($variant->error)->toBeNull();

        $this->fake->assertPublishedTimes($variant, 3);
        Event::assertNotDispatched(PostFailedToPublish::class);
    });

    it('waits at least as long as the platform asks', function (?int $retryAfter, int $expectedDelay) {
        Queue::fake();
        [, $job] = claimedPost($this->workspace, 1);
        $this->fake->failRetryable(retryAfterSeconds: $retryAfter);

        runPublishJob($job);

        expect(Queue::pushed(PublishPostJob::class)->sole()->delay)->toBe($expectedDelay);
    })->with([
        'no advice: the backoff' => [null, 60],
        'shorter than the backoff: the backoff' => [5, 60],
        'longer than the backoff: the advice' => [200, 200],
        'as long as the longest backoff' => [300, 300],
    ]);

    it('fails at once when the platform asks for a longer wait than the instance retries for', function () {
        Queue::fake();
        [$post, $job] = claimedPost($this->workspace, 1);
        $this->fake->failRetryable(retryAfterSeconds: 7200);

        runPublishJob($job);

        Queue::assertNothingPushed();
        expect(freshPost($post)->state)->toBe(PostState::Failed)
            ->and(variantsOf($post)[0]->error)->toContain('The platform is busy.')->toContain('Try again later.');
    });

    it('gives up when the attempts are exhausted and fails the post', function () {
        Event::fake([PostFailedToPublish::class]);
        [$post, $job] = claimedPost($this->workspace, 1);
        $this->fake->alwaysFail();

        // The sync queue runs each successor at once.
        dispatch($job);

        [$variant] = variantsOf($post);

        $this->fake->assertPublishedTimes($variant, 3);
        expect(freshPost($post)->state)->toBe(PostState::Failed)
            ->and($variant->error)->toBe('The platform is busy.')
            ->and($variant->isPublished())->toBeFalse();

        Event::assertDispatchedTimes(PostFailedToPublish::class, 1);
    });

    it('retries only the variant that failed, and waits for the longest advice', function () {
        Queue::fake();
        $channels = inWorkspace($this->workspace, fn () => Channel::factory()->count(3)->create()->all());
        [$post, $job] = claimedPost($this->workspace, $channels);
        [$ok, $busy, $refused] = variantsOf($post);

        $this->fake->publishReturns(function (PostVariant $variant, int $call) use ($busy, $refused): PublishResult {
            if ($variant->id === $busy->id && $call === 1) {
                throw PublishFailed::retryable('facebook', 'The platform is busy.', 90);
            }

            if ($variant->id === $refused->id) {
                throw PublishFailed::permanent('facebook', 'The platform refused the post.');
            }

            return new PublishResult("fb_{$variant->id}_{$call}", null, now());
        });

        runPublishJob($job);

        $successor = Queue::pushed(PublishPostJob::class)->sole();

        expect($successor->delay)->toBe(90)
            ->and($successor->givenUp)->toBe([$refused->id])
            ->and($successor->timeout)->toBe(45)
            ->and(freshPost($post)->state)->toBe(PostState::Publishing);

        runPublishJob($successor);

        // The published one and the refused one were not sent again.
        $this->fake->assertPublishedOnce($ok)->assertPublishedTimes($busy, 2)->assertPublishedOnce($refused);
        expect(freshPost($post)->state)->toBe(PostState::Failed)
            ->and(variantsOf($post)[1]->isPublished())->toBeTrue()
            ->and(variantsOf($post)[2]->error)->toBe('The platform refused the post.');
    });

    it('gives a sign of life on every attempt', function () {
        Queue::fake();
        [$post, $job] = claimedPost($this->workspace, 1);
        $this->fake->failRetryable();

        $this->travel(5)->minutes();
        runPublishJob($job);

        expect(freshPost($post)->updated_at?->toDateTimeString())->toBe('2026-11-24 10:05:00');
    });
});

describe('permanent failures', function () {
    it('fails at once without a retry', function () {
        Queue::fake();
        Event::fake([PostFailedToPublish::class]);
        [$post, $job] = claimedPost($this->workspace, 1);
        $this->fake->failPermanently(message: 'The text breaks the rules of the platform.');

        runPublishJob($job);

        [$variant] = variantsOf($post);

        Queue::assertNothingPushed();
        $this->fake->assertPublishedOnce($variant);
        expect(freshPost($post)->state)->toBe(PostState::Failed)
            ->and($variant->hasFailed())->toBeTrue()
            ->and($variant->error)->toBe('The text breaks the rules of the platform.');

        Event::assertDispatched(
            PostFailedToPublish::class,
            fn (PostFailedToPublish $event) => $event->postId === $post->id && $event->workspaceId === $this->workspace->id,
        );
    });

    it('marks the channel when the platform wants it connected again, and does not retry', function () {
        Queue::fake();
        [$post, $job] = claimedPost($this->workspace, 1);
        [$variant] = variantsOf($post);
        $this->fake->failReconnectRequired();

        runPublishJob($job);

        Queue::assertNothingPushed();
        $this->fake->assertPublishedOnce($variant);

        $channel = Channel::query()->withoutGlobalScopes()->findOrFail($variant->channel_id);

        expect($channel->status)->toBe(ChannelStatus::NeedsReconnect)
            ->and(freshPost($post)->state)->toBe(PostState::Failed)
            ->and(variantsOf($post)[0]->error)->toBe('The access token is no longer valid.');
    });

    it('does not retry a failure that asks for a reconnect even if it is also marked retryable', function () {
        Queue::fake();
        [$post, $job] = claimedPost($this->workspace, 1);
        $this->fake->failNextPublishes(new PublishFailed('facebook', 'Token expired.', retryable: true, requiresReconnect: true));

        runPublishJob($job);

        Queue::assertNothingPushed();
        expect(freshPost($post)->state)->toBe(PostState::Failed);
    });

    it('treats an unexpected exception as an unknown outcome: no retry, and no secrets stored', function () {
        Queue::fake();
        [$post, $job] = claimedPost($this->workspace, 1);
        $this->fake->publishReturns(fn () => throw new RuntimeException('GET https://graph.test/feed?access_token=EAAB-secret failed'));

        runPublishJob($job);

        [$variant] = variantsOf($post);

        Queue::assertNothingPushed();
        $this->fake->assertPublishedOnce($variant);
        expect($variant->error)->toBe(PublishPostJob::UNKNOWN_OUTCOME)
            ->and(freshPost($post)->state)->toBe(PostState::Failed);
    });

    it('stores a platform message without credentials', function () {
        [$post, $job] = claimedPost($this->workspace, 1);
        $this->fake->failPermanently(message: 'Bad request for access_token=EAAB-secret');

        runPublishJob($job);

        expect(variantsOf($post)[0]->error)->not->toContain('EAAB-secret');
    });
});

describe('partial success', function () {
    it('fails the post, keeps what was published, and a retry sends only the missing variant', function () {
        $channels = inWorkspace($this->workspace, fn () => Channel::factory()->count(2)->create()->all());
        [$post, $job] = claimedPost($this->workspace, $channels);
        [$out, $missing] = variantsOf($post);

        $this->fake->publishReturns(failingOnlyFor($missing, PublishFailed::permanent('facebook', 'The platform refused the post.')));

        runPublishJob($job);

        expect(freshPost($post)->state)->toBe(PostState::Failed)
            ->and(variantsOf($post)[0]->external_id)->toBe("fb_{$out->id}_1")
            ->and(variantsOf($post)[1]->hasFailed())->toBeTrue();

        // A human retries; the scheduler picks the post up again.
        $this->fake->publishReturns(fn (PostVariant $variant, int $call) => new PublishResult("fb_{$variant->id}_{$call}", null, now()));
        $editor = userWithRole($this->workspace, Role::Editor);
        $retry = inWorkspace($this->workspace, function () use ($editor, $post) {
            app(RetryPost::class)->handle($editor, $post);
            $post->claimForPublishing();

            return PublishPostJob::forClaimed($post);
        });

        expect($retry->timeout)->toBe(45);
        runPublishJob($retry);

        [$out, $missing] = variantsOf($post);

        $this->fake->assertPublishedOnce($out)->assertPublishedTimes($missing, 2);
        expect(freshPost($post)->state)->toBe(PostState::Published)
            ->and($out->external_id)->toBe("fb_{$out->id}_1")
            ->and($missing->external_id)->toBe("fb_{$missing->id}_2")
            ->and($missing->error)->toBeNull();
    });
});

describe('things that changed since the post was claimed', function () {
    it('does not call the platform for a channel that can no longer publish', function (Closure $change, string $expected) {
        $channel = inWorkspace($this->workspace, fn () => Channel::factory()->create(['display_name' => 'Harbour News']));
        [$post, $job] = claimedPost($this->workspace, [$channel]);

        inWorkspace($this->workspace, fn () => $change($channel));
        runPublishJob($job);

        $this->fake->assertNothingPublished();
        expect(freshPost($post)->state)->toBe(PostState::Failed)
            ->and(variantsOf($post)[0]->error)->toBe($expected);
    })->with([
        'disconnected' => [fn (Channel $channel) => $channel->disconnect(), 'Harbour News is disconnected.'],
        'needs reconnect' => [fn (Channel $channel) => $channel->markNeedsReconnect(), 'Harbour News must be connected again before it can publish.'],
        'token expired' => [fn (Channel $channel) => $channel->update(['token_expires_at' => now()->subMinute()]), 'The access to Harbour News has expired. Connect it again.'],
    ]);

    it('still publishes the variants of other channels', function () {
        $channels = inWorkspace($this->workspace, fn () => Channel::factory()->count(2)->create()->all());
        [$post, $job] = claimedPost($this->workspace, $channels);
        [$first, $second] = variantsOf($post);

        inWorkspace($this->workspace, fn () => $channels[0]->disconnect());
        runPublishJob($job);

        $this->fake->assertNotPublished($first)->assertPublishedOnce($second);
        expect(freshPost($post)->state)->toBe(PostState::Failed);
    });

    it('fails a variant whose platform has no adapter any more', function () {
        $channel = inWorkspace($this->workspace, fn () => Channel::factory()->platform('telegraph')->create());
        [$post, $job] = claimedPost($this->workspace, [$channel]);

        runPublishJob($job);

        expect(freshPost($post)->state)->toBe(PostState::Failed)
            ->and(variantsOf($post)[0]->error)->toBe('Publishing to telegraph is not available on this instance.');
    });

    it('does nothing when the post is gone', function () {
        [$post, $job] = claimedPost($this->workspace, 1);
        DB::table('posts')->where('id', $post->id)->delete();

        runPublishJob($job);

        $this->fake->assertNothingPublished();
    });

    it('does nothing when the workspace is gone', function () {
        [, $job] = claimedPost($this->workspace, 1);
        DB::table('workspaces')->where('id', $this->workspace->id)->delete();

        runPublishJob($job);

        $this->fake->assertNothingPublished();
    });

    it('skips a variant that is gone and finishes with the others', function () {
        [$post, $job] = claimedPost($this->workspace, 2);
        [$gone, $kept] = variantsOf($post);

        $this->fake->publishReturns(function (PostVariant $variant, int $call) use ($kept): PublishResult {
            DB::table('post_variants')->where('id', $kept->id)->delete();

            return new PublishResult("fb_{$variant->id}_{$call}", null, now());
        });

        runPublishJob($job);

        $this->fake->assertPublishedOnce($gone)->assertNotPublished($kept);
        expect(freshPost($post)->state)->toBe(PostState::Published);
    });
});

describe('the workspace', function () {
    it('is the current workspace while the adapter runs, and only then', function () {
        $other = Workspace::factory()->create();
        [$post, $job] = claimedPost($this->workspace, 1);
        [$foreignPost, $foreignJob] = claimedPost($other, 1);
        $seen = [];

        $this->fake->publishReturns(function (PostVariant $variant, int $call) use (&$seen): PublishResult {
            $seen[$variant->workspace_id] = app(CurrentWorkspace::class)->id();

            return new PublishResult("fb_{$variant->id}_{$call}", null, now());
        });

        runPublishJob($job);
        expect(app(CurrentWorkspace::class)->get())->toBeNull();

        inWorkspace($this->workspace, fn () => runPublishJob($foreignJob));

        expect($seen)->toBe([$this->workspace->id => $this->workspace->id, $other->id => $other->id])
            ->and(freshPost($post)->state)->toBe(PostState::Published)
            ->and(freshPost($foreignPost)->state)->toBe(PostState::Published);
    });

    it('cannot publish a post through a job that names another workspace', function () {
        $other = Workspace::factory()->create();
        [$post, $job] = claimedPost($this->workspace, 1);

        runPublishJob(new PublishPostJob($other->id, $job->postId, $job->round));

        $this->fake->assertNothingPublished();
        expect(freshPost($post)->state)->toBe(PostState::Publishing);
    });
});

describe('a job the queue gave up on', function () {
    it('settles its post as failed without calling the platform', function () {
        Event::fake([PostFailedToPublish::class]);
        [$post, $job] = claimedPost($this->workspace, 2);

        $job->failed(new RuntimeException('The worker ran out of time.'));

        $this->fake->assertNothingPublished();
        expect(freshPost($post)->state)->toBe(PostState::Failed)
            ->and(variantsOf($post)[0]->error)->toBe(SettleInterruptedPost::MESSAGE);

        Event::assertDispatchedTimes(PostFailedToPublish::class, 1);
    });

    it('settles the post when the job throws outside a platform call', function () {
        [$post, $job] = claimedPost($this->workspace, 1);
        app()->bind(ChannelManager::class, fn () => throw new RuntimeException('Container is broken.'));

        expect(fn () => dispatch($job))->toThrow(RuntimeException::class);

        $this->fake->assertNothingPublished();
        expect(freshPost($post)->state)->toBe(PostState::Failed);
    });

    it('leaves the post alone when the queue delivered the job a second time', function () {
        [$post, $job] = claimedPost($this->workspace, 1);

        $job->failed(new MaxAttemptsExceededException('Attempted too many times.'));

        expect(freshPost($post)->state)->toBe(PostState::Publishing)
            ->and(variantsOf($post)[0]->error)->toBeNull();
    });

    it('leaves a post of a later round alone', function () {
        [$post, $job] = claimedPost($this->workspace, 1);

        (new PublishPostJob($job->workspaceId, $job->postId, $job->round - 60))->failed(new RuntimeException('Late.'));

        expect(freshPost($post)->state)->toBe(PostState::Publishing);
    });
});
