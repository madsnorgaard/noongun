<?php

use App\Domain\Content\Models\Post;
use App\Domain\Content\PostState;
use App\Domain\Workspaces\Models\Workspace;
use App\Domain\Workspaces\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../../Support/publishing.php';
require_once __DIR__.'/../../Support/posts.php';

beforeEach(function () {
    $this->travelTo('2026-11-24 10:00:00');
    Http::preventStrayRequests();

    $this->fake = fakeChannelAdapter();
    $this->workspace = Workspace::factory()->create(['timezone' => 'Africa/Johannesburg']);
    $this->owner = userWithRole($this->workspace, Role::Owner);
    $this->channel = channelIn($this->workspace, ['display_name' => 'Alpha']);
});

it('lists drafts first, then what is approved or scheduled in time order, then what failed', function () {
    $draftUnplanned = postInState($this->workspace, PostState::Draft, [$this->channel]);
    $draftLater = postInState($this->workspace, PostState::Draft, [$this->channel], now()->addDays(3));
    $draftSoon = postInState($this->workspace, PostState::Draft, [$this->channel], now()->addDay());
    $draftUnplannedNewer = postInState($this->workspace, PostState::Draft, [$this->channel]);

    $approved = postInState($this->workspace, PostState::Approved, [$this->channel]);
    $scheduledLater = postInState($this->workspace, PostState::Scheduled, [$this->channel], now()->addDays(2));
    $publishing = postInState($this->workspace, PostState::Publishing, [$this->channel]);
    $scheduledSoon = postInState($this->workspace, PostState::Scheduled, [$this->channel], now()->addHour());

    $failedOlder = postInState($this->workspace, PostState::Failed, [$this->channel]);
    $failedNewer = postInState($this->workspace, PostState::Failed, [$this->channel]);
    DB::table('posts')->where('id', $failedOlder->id)->update(['updated_at' => now()->subHours(2)]);
    DB::table('posts')->where('id', $failedNewer->id)->update(['updated_at' => now()->subHour()]);

    $published = postInState($this->workspace, PostState::Published, [$this->channel]);
    $rejected = postInState($this->workspace, PostState::Rejected, [$this->channel]);

    $this->actingAs($this->owner)->get(route('queue.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('queue/Index')
            ->where('view', 'working')
            ->where('history', null)
            ->where('can.manage', true)
            ->where('sections.drafts', fn ($posts) => collect($posts)->pluck('id')->all() === [
                $draftSoon->id, $draftLater->id, $draftUnplanned->id, $draftUnplannedNewer->id,
            ])
            ->where('sections.upcoming', fn ($posts) => collect($posts)->pluck('id')->all() === [
                $publishing->id, $scheduledSoon->id, $scheduledLater->id, $approved->id,
            ])
            ->where('sections.upcoming', fn ($posts) => collect($posts)->pluck('state')->all() === [
                'publishing', 'scheduled', 'scheduled', 'approved',
            ])
            ->where('sections.failed', fn ($posts) => collect($posts)->pluck('id')->all() === [
                $failedNewer->id, $failedOlder->id,
            ]));

    $this->get(route('queue.index', ['view' => 'history']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('queue/Index')
            ->where('view', 'history')
            ->where('sections', null)
            ->where('history.posts', fn ($posts) => collect($posts)->pluck('id')->sort()->values()->all() === [
                $published->id, $rejected->id,
            ])
            ->where('history.previous', null)
            ->where('history.next', null));
});

it('gives each draft what is needed to review and approve it in place', function () {
    $ready = postInState($this->workspace, PostState::Draft, [$this->channel], now()->addDay());
    $empty = postInState($this->workspace, PostState::Draft, [$this->channel]);
    setBody($empty, '');

    $this->actingAs($this->owner)->get(route('queue.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('sections.drafts.0.id', $ready->id)
            ->where('sections.drafts.0.version', postVersion($ready))
            ->where('sections.drafts.0.variants.0.body', variantsOf($ready)[0]->body)
            ->where('sections.drafts.0.variants.0.channel_name', 'Alpha')
            ->where('sections.drafts.0.scheduled_at', '2026-11-25T10:00:00+00:00')
            // 10:00 UTC is 12:00 in Johannesburg.
            ->where('sections.drafts.0.scheduled_local', '2026-11-25T12:00')
            ->where('sections.drafts.0.problems', [])
            ->where('sections.drafts.0.can.approve', true)
            ->where('sections.drafts.1.id', $empty->id)
            ->where('sections.drafts.1.problems', ['variants.'.variantsOf($empty)[0]->id.'.body' => ['The text for Alpha is empty.']]));
});

it('says how many posts a section has when it shows only the first hundred', function () {
    inWorkspace($this->workspace, fn () => Post::factory()->count(103)->create());
    postInState($this->workspace, PostState::Failed, [$this->channel]);

    $this->actingAs($this->owner)->get(route('queue.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('sections.drafts', 100)
            ->has('sections.failed', 1)
            ->where('totals', ['drafts' => 103, 'upcoming' => 0, 'failed' => 1]));

    $this->get(route('queue.index', ['view' => 'history']))
        ->assertInertia(fn (Assert $page) => $page->where('totals', null));
});

it('shows the permalink of published posts in the history, newest first', function () {
    $older = postInState($this->workspace, PostState::Published, [$this->channel]);
    $newer = postInState($this->workspace, PostState::Published, [$this->channel]);
    DB::table('posts')->where('id', $older->id)->update(['updated_at' => now()->subDay()]);

    $this->actingAs($this->owner)->get(route('queue.index', ['view' => 'history']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('history.posts.0.id', $newer->id)
            ->where('history.posts.1.id', $older->id)
            ->where('history.posts.0.variants.0.permalink', 'https://platform.test/posts/ext_'.variantsOf($newer)[0]->id));
});

it('lets every role look, and only owners and editors act', function (Role $role, bool $manage) {
    postInState($this->workspace, PostState::Draft, [$this->channel]);
    postInState($this->workspace, PostState::Scheduled, [$this->channel]);
    postInState($this->workspace, PostState::Failed, [$this->channel]);

    $response = $this->actingAs(userWithRole($this->workspace, $role))->get(route('queue.index'));

    $response->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('can.manage', $manage)
        ->where('sections.drafts.0.can.approve', $manage)
        ->where('sections.drafts.0.can.reject', $manage)
        ->where('sections.drafts.0.can.delete', $manage)
        ->where('sections.upcoming.0.can.unschedule', $manage)
        ->where('sections.failed.0.can.retry', $manage));

    expect($response->getContent())->not->toContain('test-access-');
})->with([
    'owner' => [Role::Owner, true],
    'editor' => [Role::Editor, true],
    'viewer' => [Role::Viewer, false],
]);

it('shows only the posts of the current workspace', function () {
    $mine = postInState($this->workspace, PostState::Draft, [$this->channel]);
    $elsewhere = Workspace::factory()->create();
    postInState($elsewhere, PostState::Draft, 1);
    postInState($elsewhere, PostState::Scheduled, 1);
    postInState($elsewhere, PostState::Failed, 1);
    postInState($elsewhere, PostState::Published, 1);

    $this->actingAs($this->owner)->get(route('queue.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('sections.drafts', 1)
            ->where('sections.drafts.0.id', $mine->id)
            ->has('sections.upcoming', 0)
            ->has('sections.failed', 0));

    $this->get(route('queue.index', ['view' => 'history']))
        ->assertInertia(fn (Assert $page) => $page->has('history.posts', 0));
});

it('never approves, schedules or sends anything by being opened', function () {
    $draft = postInState($this->workspace, PostState::Draft, [$this->channel], now()->subMinute());
    $approved = postInState($this->workspace, PostState::Approved, [$this->channel]);

    $this->actingAs($this->owner);
    $this->get(route('queue.index'))->assertOk();
    $this->get(route('queue.index', ['view' => 'history']))->assertOk();
    $this->get(route('calendar.index'))->assertOk();
    $this->get(route('posts.show', $draft->id))->assertOk();

    expect(stateOf($draft))->toBe('draft')
        ->and(freshPost($draft)->approved_at)->toBeNull()
        ->and(stateOf($approved))->toBe('approved');

    $this->fake->assertNothingPublished();
});

it('is closed to guests and to users without a workspace', function () {
    $this->get(route('queue.index'))->assertRedirect(route('login'));

    $this->actingAs(User::factory()->create())->get(route('queue.index'))->assertForbidden();
});
