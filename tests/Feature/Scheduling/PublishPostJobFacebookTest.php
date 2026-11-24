<?php

/*
 * The job and the real Facebook adapter together, against the faked Graph
 * API. Everything else about the job is tested with the fake adapter.
 */

use App\Domain\Channels\Models\Channel;
use App\Domain\Content\Models\Post;
use App\Domain\Content\Models\PostVariant;
use App\Domain\Content\PostState;
use App\Domain\Scheduling\PublishPostJob;
use App\Domain\Workspaces\Models\Workspace;
use App\Domain\Workspaces\Role;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/../../Support/publishing.php';
require_once __DIR__.'/../../Support/facebook.php';

beforeEach(function () {
    $this->travelTo('2026-11-24 10:15:00');
    configureFacebook();

    $this->workspace = Workspace::factory()->create(['timezone' => 'Africa/Johannesburg']);
    $this->channel = inWorkspace($this->workspace, fn () => Channel::factory()->create([
        'external_id' => '100000000000001',
        'display_name' => 'Table Bay Coffee',
        'access_token' => 'test-page-token-table-bay-coffee',
    ]));

    $this->post = inWorkspace($this->workspace, function (): Post {
        $post = Post::factory()->create();
        PostVariant::factory()->create(['post_id' => $post->id, 'channel_id' => $this->channel->id, 'body' => 'Fresh beans arrive on Thursday.']);

        return $post;
    });

    // Approved and scheduled the way the factories do it, then claimed the
    // way the scheduler does.
    $this->claim = function (): PublishPostJob {
        $owner = userWithRole($this->workspace, Role::Owner);

        return inWorkspace($this->workspace, function () use ($owner): PublishPostJob {
            $this->post->approve($owner)->schedule(now()->subMinute());
            expect($this->post->claimForPublishing())->toBeTrue();

            return PublishPostJob::forClaimed($this->post);
        });
    };
});

it('makes one call to the Page feed when the job is dispatched twice, and records the permalink', function () {
    fakeGraph();
    $job = ($this->claim)();

    dispatch(clone $job);
    dispatch(clone $job);
    runPublishJob(unserialize(serialize($job)));

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && $request->url() === FACEBOOK_TEST_GRAPH.'/100000000000001/feed'
        && $request['message'] === 'Fresh beans arrive on Thursday.');

    [$variant] = variantsOf($this->post);

    expect(freshPost($this->post)->state)->toBe(PostState::Published)
        ->and($variant->external_id)->toBe('100000000000001_900000000000001')
        ->and($variant->permalink)->toBe('https://www.facebook.test/100000000000001_900000000000001')
        ->and($variant->published_at?->toDateTimeString())->toBe('2026-11-24 10:15:00')
        ->and($variant->error)->toBeNull()
        ->and($variant->hasUnknownOutcome())->toBeFalse();
});

it('retries after a rate limit and makes two calls in all', function () {
    Queue::fake();
    $calls = 0;
    fakeGraph(['feed' => function () use (&$calls) {
        return ++$calls === 1 ? facebookResponse('error_rate_limit', 400) : facebookResponse('feed_post');
    }]);
    $job = ($this->claim)();

    runPublishJob($job);

    expect(freshPost($this->post)->state)->toBe(PostState::Publishing)
        ->and(variantsOf($this->post)[0]->hasUnknownOutcome())->toBeFalse();

    runPublishJob(Queue::pushed(PublishPostJob::class)->sole());
    runPublishJob(unserialize(serialize($job)));

    Http::assertSentCount(2);
    expect(freshPost($this->post)->state)->toBe(PostState::Published)
        ->and(variantsOf($this->post)[0]->external_id)->toBe('100000000000001_900000000000001');
});

it('never calls the feed again after an answer that leaves the outcome unknown', function (Closure $response) {
    Queue::fake();
    $calls = 0;
    fakeGraph(['feed' => function (Request $request) use ($response, &$calls) {
        $calls++;

        return $response($request);
    }]);
    $job = ($this->claim)();

    runPublishJob($job);
    runPublishJob(unserialize(serialize($job)));

    // Counted here: a call that ends in a timeout is not in Http's record.
    expect($calls)->toBe(1);
    Queue::assertNothingPushed();

    [$variant] = variantsOf($this->post);

    expect(freshPost($this->post)->state)->toBe(PostState::Failed)
        ->and($variant->hasUnknownOutcome())->toBeTrue()
        ->and($variant->error)->toContain('Check the Page before trying again')
        ->and($variant->error)->not->toContain('test-page-token');
})->with(facebookAmbiguousFailures());

it('clears the doubt when Facebook refuses the post outright', function () {
    fakeGraph(['feed' => facebookResponse('error_validation', 400)]);

    runPublishJob(($this->claim)());

    Http::assertSentCount(1);
    expect(freshPost($this->post)->state)->toBe(PostState::Failed)
        ->and(variantsOf($this->post)[0]->hasUnknownOutcome())->toBeFalse();
});
