<?php

/*
 * Project rule 1 for "Propose a week": a whole proposal, from the request
 * of a person to the last job, leaves drafts and nothing else. Approving
 * stays the act of a person, one post at a time, as in M3.
 *
 * It stands next to GeneratedPostsStayDraftsTest.php. If it fails because
 * of a change you made, the change is wrong, not the test.
 */

use App\Domain\Content\Models\Post;
use App\Domain\Drafting\GenerateDraftJob;
use App\Domain\Scheduling\PublishPostJob;
use App\Domain\Workspaces\Models\Workspace;
use App\Domain\Workspaces\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;

require_once __DIR__.'/../../Support/publishing.php';
require_once __DIR__.'/../../Support/posts.php';
require_once __DIR__.'/../../Support/proposals.php';
require_once __DIR__.'/../../Support/sources.php';

beforeEach(function () {
    $this->travelTo('2026-11-25 10:00:00');
    Queue::fake();

    $this->adapter = fakeChannelAdapter();
    $this->generator = fakeDraftGenerator();
});

it('leaves a whole proposed week as drafts that nothing publishes, also after their times have passed', function (string $timezone) {
    $workspace = Workspace::factory()->create(['timezone' => $timezone]);
    $owner = userWithRole($workspace, Role::Owner);
    $first = channelIn($workspace);
    $second = channelIn($workspace);
    briefIn($workspace);
    pillarIn($workspace, ['weekly_target' => 2]);

    foreach ([1, 2, 3, 4, 5] as $weekday) {
        slotIn($workspace, $first, $weekday, '09:00');
    }

    slotIn($workspace, $second, 1, '09:00');
    slotIn($workspace, $second, 6, '18:30');

    // One provider failure that is tried again, one that is not.
    $this->generator->failRetryable()->failPermanently();

    $this->actingAs($owner)
        ->post(route('proposals.store'), ['week' => '2026-11-30', 'plan' => planFor($workspace, '2026-11-30')->fingerprint()])
        ->assertRedirect(route('queue.index'))
        ->assertSessionHasNoErrors();

    expect(DB::table('posts')->count())->toBe(0);

    runQueuedDraftJobs();

    // Six planned posts: one failed for good, one was written on its second try.
    expect(DB::table('draft_runs')->where('status', 'succeeded')->count())->toBe(5)
        ->and(DB::table('draft_runs')->where('status', 'failed')->count())->toBe(2)
        ->and(DB::table('draft_runs')->whereIn('status', ['pending', 'running'])->count())->toBe(0)
        ->and(DB::table('posts')->count())->toBe(5);

    $untouched = fn () => DB::table('posts')->orderBy('id')->get(['id', 'state', 'approved_by', 'approved_at', 'scheduled_at', 'publish_round'])->all();
    $before = $untouched();

    foreach ($before as $post) {
        expect($post->state)->toBe('draft')
            ->and($post->approved_by)->toBeNull()
            ->and($post->approved_at)->toBeNull()
            ->and($post->scheduled_at)->not->toBeNull()
            ->and($post->publish_round)->toBe(0);
    }

    expect(DB::table('post_variants')->whereNotNull('external_id')->count())->toBe(0);

    // Before, at and long after the planned times, everything that runs by itself runs.
    foreach (['2026-11-29 12:00:00', '2026-11-30 08:00:00', '2026-12-02 12:00:00', '2026-12-20 12:00:00'] as $moment) {
        $this->travelTo($moment);

        $this->artisan('noongun:dispatch-due-posts')->assertSuccessful();
        $this->artisan('noongun:recover-stuck-posts')->assertSuccessful();
        $this->artisan('noongun:recover-stuck-draft-runs')->assertSuccessful();
        runQueuedDraftJobs();
    }

    Queue::assertNotPushed(PublishPostJob::class);
    $this->adapter->assertNothingPublished();

    expect($untouched())->toEqual($before)
        ->and($this->adapter->calls)->toBe([])
        ->and(DB::table('post_variants')->whereNotNull('external_id')->count())->toBe(0);
})->with(['Europe/Copenhagen', 'Africa/Johannesburg']);

it('publishes a generated draft only after a person approved that one post', function () {
    $workspace = Workspace::factory()->create(['timezone' => 'Europe/Copenhagen']);
    $owner = userWithRole($workspace, Role::Owner);
    $channel = channelIn($workspace);
    briefIn($workspace);

    foreach ([1, 2, 3] as $weekday) {
        slotIn($workspace, $channel, $weekday, '09:00');
    }

    $this->actingAs($owner)
        ->post(route('proposals.store'), ['week' => '2026-11-30', 'plan' => planFor($workspace, '2026-11-30')->fingerprint()])
        ->assertSessionHasNoErrors();

    runQueuedDraftJobs();

    $posts = DB::table('posts')->orderBy('scheduled_at')->pluck('id')->all();

    $chosen = Post::query()->withoutGlobalScopes()->findOrFail($posts[1]);

    $this->post(route('posts.approve', $chosen->id), ['version' => postVersion($chosen)])
        ->assertSessionHasNoErrors();

    expect(DB::table('posts')->orderBy('scheduled_at')->pluck('state')->all())->toBe(['draft', 'scheduled', 'draft'])
        ->and(DB::table('posts')->where('id', $posts[1])->value('approved_by'))->toBe($owner->id);

    $this->travelTo('2026-12-20 12:00:00');
    $this->artisan('noongun:dispatch-due-posts')->assertSuccessful();

    Queue::assertPushed(PublishPostJob::class, 1);
    Queue::assertPushed(PublishPostJob::class, fn (PublishPostJob $job): bool => $job->postId === $posts[1]);

    expect(DB::table('posts')->orderBy('scheduled_at')->pluck('state')->all())->toBe(['draft', 'publishing', 'draft']);
});

it('has no way to approve what a proposal made other than the approval of M3', function () {
    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => str_contains((string) $route->getActionName(), 'ProposalController'))
        ->map(fn ($route) => $route->getName())
        ->values()
        ->all();

    expect($routes)->toEqualCanonicalizing(['proposals.create', 'proposals.store'])
        ->and(sourcesMatching('/\bApprovePost\b|->approve\(|approveSelected|approve_all|approveAll/i', 'Http/Controllers/Drafting/'))->toBe([])
        ->and(sourcesMatching('/\bApprovePost\b|approve_all|approveAll/i', 'Http/Presenters/ProposalPresenter.php'))->toBe([])
        ->and(sourcesMatching('/ShouldBeUnique|public (int )?\$tries = (?!1;)/', 'Domain/Drafting/GenerateDraftJob.php'))->toBe([]);
});

it('carries nothing but ids in the jobs a proposal queues', function () {
    $workspace = Workspace::factory()->create();
    $owner = userWithRole($workspace, Role::Owner);
    briefIn($workspace);
    slotIn($workspace, channelIn($workspace), 1, '09:00');

    $this->actingAs($owner)
        ->post(route('proposals.store'), ['week' => '2026-11-30', 'plan' => planFor($workspace, '2026-11-30')->fingerprint()])
        ->assertSessionHasNoErrors();

    $job = Queue::pushed(GenerateDraftJob::class)->sole();
    $payload = serialize($job);

    expect($job->workspaceId)->toBe($workspace->id)
        ->and($job->runId)->toBe(DB::table('draft_runs')->value('id'))
        ->and($payload)->not->toContain(DRAFT_TEST_KEY)
        ->and($payload)->not->toContain('LlmKey')
        ->and($payload)->not->toContain('Table Bay');
});
