<?php

/*
 * The job and the real LinkedIn adapter together, against the faked LinkedIn
 * API. Everything else about the job is tested with the fake adapter.
 */

use App\Domain\Channels\ChannelStatus;
use App\Domain\Channels\Models\Channel;
use App\Domain\Content\Models\Post;
use App\Domain\Content\Models\PostVariant;
use App\Domain\Content\PostState;
use App\Domain\Scheduling\PublishPostJob;
use App\Domain\Workspaces\Models\Workspace;
use App\Domain\Workspaces\Role;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/../../Support/publishing.php';
require_once __DIR__.'/../../Support/linkedin.php';

beforeEach(function () {
    $this->travelTo('2026-11-25 10:15:00');
    configureLinkedIn();

    $this->workspace = Workspace::factory()->create(['timezone' => 'Europe/Copenhagen']);
    $this->channel = inWorkspace($this->workspace, fn () => Channel::factory()->platform('linkedin')->create([
        'external_id' => LINKEDIN_TEST_MEMBER,
        'display_name' => 'Thandi Mokoena',
        'access_token' => LINKEDIN_TEST_TOKEN,
        'token_expires_at' => '2027-01-24 10:00:00',
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

    $this->logged = '';
    Event::listen(MessageLogged::class, function (MessageLogged $entry) {
        $this->logged .= $entry->message.json_encode($entry->context);
    });
});

it('makes one call to the Posts API when the job is dispatched twice, and records the URN and permalink', function () {
    fakeLinkedIn();
    $job = ($this->claim)();

    dispatch(clone $job);
    dispatch(clone $job);
    runPublishJob(unserialize(serialize($job)));

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && $request->url() === LINKEDIN_TEST_API.'/rest/posts'
        && $request['commentary'] === 'Fresh beans arrive on Thursday.');

    [$variant] = variantsOf($this->post);

    expect(freshPost($this->post)->state)->toBe(PostState::Published)
        ->and($variant->external_id)->toBe(LINKEDIN_TEST_POST)
        ->and($variant->permalink)->toBe(LINKEDIN_TEST_WEB.'/feed/update/'.LINKEDIN_TEST_POST.'/')
        ->and($variant->published_at?->toDateTimeString())->toBe('2026-11-25 10:15:00')
        ->and($variant->error)->toBeNull()
        ->and($variant->hasUnknownOutcome())->toBeFalse();
});

it('sends nothing for a post that was never approved', function () {
    fakeLinkedIn();

    runPublishJob(new PublishPostJob($this->workspace->id, $this->post->id, round: 1));

    Http::assertNothingSent();
    expect(freshPost($this->post)->state)->toBe(PostState::Draft);
});

it('retries after a throttled call and makes two calls in all', function () {
    Queue::fake();
    $calls = 0;
    fakeLinkedIn(['posts' => function () use (&$calls) {
        return ++$calls === 1 ? linkedInResponse('error_rate_limit', 429) : linkedInCreated();
    }]);
    $job = ($this->claim)();

    runPublishJob($job);

    expect(freshPost($this->post)->state)->toBe(PostState::Publishing)
        ->and(variantsOf($this->post)[0]->hasUnknownOutcome())->toBeFalse();

    runPublishJob(Queue::pushed(PublishPostJob::class)->sole());
    runPublishJob(unserialize(serialize($job)));

    Http::assertSentCount(2);
    expect(freshPost($this->post)->state)->toBe(PostState::Published)
        ->and(variantsOf($this->post)[0]->external_id)->toBe(LINKEDIN_TEST_POST);
});

it('never calls the Posts API again after an answer that leaves the outcome unknown', function (Closure $response) {
    Queue::fake();
    $calls = 0;
    fakeLinkedIn(['posts' => function (Request $request) use ($response, &$calls) {
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
        ->and($variant->error)->toContain('Check the profile before trying again')
        ->and($variant->error)->not->toContain(LINKEDIN_TEST_TOKEN)
        ->and($this->logged)->toContain('Publishing a variant failed.')
        ->and($this->logged)->not->toContain(LINKEDIN_TEST_TOKEN)->not->toContain(LINKEDIN_TEST_SECRET);
})->with(linkedInAmbiguousFailures());

it('clears the doubt when LinkedIn refuses the post outright', function () {
    fakeLinkedIn(['posts' => linkedInResponse('error_validation', 422)]);

    runPublishJob(($this->claim)());

    Http::assertSentCount(1);
    expect(freshPost($this->post)->state)->toBe(PostState::Failed)
        ->and(variantsOf($this->post)[0]->hasUnknownOutcome())->toBeFalse()
        ->and(inWorkspace($this->workspace, fn () => $this->channel->fresh())->status)->toBe(ChannelStatus::Active);
});

it('marks the channel for a reconnect when LinkedIn no longer accepts the token, and keeps the token out of the log', function (string $fixture, int $status) {
    Queue::fake();
    fakeLinkedIn(['posts' => linkedInResponse($fixture, $status)]);

    runPublishJob(($this->claim)());

    Http::assertSentCount(1);
    Queue::assertNothingPushed();

    expect(freshPost($this->post)->state)->toBe(PostState::Failed)
        ->and(variantsOf($this->post)[0]->hasUnknownOutcome())->toBeFalse()
        ->and(inWorkspace($this->workspace, fn () => $this->channel->fresh())->status)->toBe(ChannelStatus::NeedsReconnect)
        ->and($this->logged)->not->toContain(LINKEDIN_TEST_TOKEN);
})->with([
    'expired' => ['error_token_expired', 401],
    'revoked' => ['error_token_revoked', 401],
    'scope missing' => ['error_permission', 403],
]);

it('does not call LinkedIn when the stored expiry has passed', function () {
    fakeLinkedIn();
    $job = ($this->claim)();

    $this->travelTo('2027-01-25 10:00:00');

    runPublishJob($job);

    Http::assertNothingSent();
    expect(freshPost($this->post)->state)->toBe(PostState::Failed)
        ->and(variantsOf($this->post)[0]->error)->toBe('The access to Thandi Mokoena has expired. Connect it again.');
});
