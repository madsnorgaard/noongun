<?php

use App\Domain\Content\PostState;
use App\Domain\Scheduling\Exceptions\LockStoreNotShared;
use App\Domain\Scheduling\PublishingConfig;
use App\Domain\Scheduling\VariantLock;
use App\Domain\Workspaces\Models\Workspace;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/../../Support/publishing.php';

beforeEach(function () {
    $this->travelTo('2026-11-24 10:00:00');

    $this->fake = fakeChannelAdapter();
    $this->workspace = Workspace::factory()->create();
});

/**
 * Makes the application believe it is not under test, with the given cache
 * driver as the default store.
 */
function outsideTestsWithCacheDriver(string $driver): void
{
    config(['cache.default' => 'locks', 'cache.stores.locks' => ['driver' => $driver]]);

    app()->detectEnvironment(fn () => 'production');
}

describe('the queue settings', function () {
    it('never hand a publishing job to a second worker while the first can still be running', function (string $connection) {
        $retryAfter = config("queue.connections.{$connection}.retry_after");

        expect($retryAfter)->toBeInt()
            ->and($retryAfter)->toBeGreaterThan(PublishingConfig::longestJobSeconds());
    })->with(['redis', 'database']);

    it('bound the time limit of a job by the calls it may make', function () {
        config(['noongun.publishing.timeout' => 30, 'noongun.publishing.calls_per_job' => 3]);

        expect(PublishingConfig::longestJobSeconds())->toBe(105)
            ->and(PublishingConfig::jobSeconds(1))->toBe(45)
            ->and(PublishingConfig::jobSeconds(0))->toBe(45)
            ->and(PublishingConfig::jobSeconds(50))->toBe(105);

        config(['noongun.publishing.calls_per_job' => 0]);

        expect(PublishingConfig::callsPerJob())->toBe(1);
    });

    it('keep the lock on a variant alive longer than any job', function (int $configured) {
        config(['noongun.publishing.lock_seconds' => $configured]);

        expect(PublishingConfig::lockSeconds())->toBeGreaterThan(PublishingConfig::longestJobSeconds());
    })->with([1, 120, 600]);
});

describe('a cache store that is not shared between workers', function () {
    it('is accepted under test, where everything runs in one process', function () {
        config(['cache.default' => 'array']);

        VariantLock::assertStoreIsShared();

        expect(VariantLock::for(1, 5)->get())->toBeTrue();
    });

    it('is refused everywhere else', function (string $driver) {
        outsideTestsWithCacheDriver($driver);

        expect(fn () => VariantLock::assertStoreIsShared())->toThrow(LockStoreNotShared::class, 'Set CACHE_STORE to redis or database')
            ->and(fn () => VariantLock::for(1, 5))->toThrow(LockStoreNotShared::class);
    })->with(['array', 'file', 'null']);

    it('is fine when it is Redis or the database', function (string $driver) {
        outsideTestsWithCacheDriver($driver);

        VariantLock::assertStoreIsShared();
    })->with(['redis', 'database', 'memcached'])->throwsNoExceptions();

    it('stops the scheduler before it claims anything', function () {
        Queue::fake();
        $post = postInState($this->workspace, PostState::Scheduled, 1, now()->subMinute());
        outsideTestsWithCacheDriver('array');

        $this->artisan('noongun:dispatch-due-posts')
            ->expectsOutputToContain('Publishing is stopped')
            ->assertFailed();

        Queue::assertNothingPushed();
        expect(freshPost($post)->state)->toBe(PostState::Scheduled);
    });

    it('stops the job before it uses its step, so it can run once the store is fixed', function () {
        [$post, $job] = claimedPost($this->workspace, 1);
        config(['cache.default' => 'locks', 'cache.stores.locks' => ['driver' => 'array']]);
        app()->detectEnvironment(fn () => 'production');

        expect(fn () => runPublishJob($job))->toThrow(LockStoreNotShared::class);
        $job->failed(new LockStoreNotShared('Publishing is stopped.'));

        $this->fake->assertNothingPublished();
        expect(freshPost($post)->state)->toBe(PostState::Publishing)
            ->and(freshPost($post)->publish_step)->toBe(0)
            ->and(variantsOf($post)[0]->error)->toBeNull();

        app()->detectEnvironment(fn () => 'testing');
        runPublishJob($job);

        $this->fake->assertPublishedOnce(variantsOf($post)[0]);
        expect(freshPost($post)->state)->toBe(PostState::Published);
    });

    it('stops the recovery as well', function () {
        [$post] = claimedPost($this->workspace, 1);
        $this->travel(3)->hours();
        outsideTestsWithCacheDriver('array');

        $this->artisan('noongun:recover-stuck-posts')
            ->expectsOutputToContain('Publishing is stopped')
            ->assertFailed();

        expect(freshPost($post)->state)->toBe(PostState::Publishing);
    });
});
