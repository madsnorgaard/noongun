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
    // A Tuesday. The week runs from Monday 23 to Sunday 29 November.
    $this->travelTo('2026-11-24 10:00:00');
    Http::preventStrayRequests();

    $this->fake = fakeChannelAdapter();
    $this->workspace = Workspace::factory()->create(['timezone' => 'Europe/Copenhagen']);
    $this->owner = userWithRole($this->workspace, Role::Owner);
    $this->channel = channelIn($this->workspace, ['display_name' => 'Alpha']);
});

/**
 * A post in the given state at the given instant in UTC.
 */
function postAt(Workspace $workspace, PostState $state, array $channels, string $utc): Post
{
    $post = postInState($workspace, $state, $channels);

    DB::table('posts')->where('id', $post->id)->update(['scheduled_at' => $utc]);

    return $post;
}

/**
 * The entries of the calendar response, keyed by post id, each with the day
 * it is shown on.
 *
 * @param  iterable<int, array<string, mixed>>  $days
 * @return array<int, array<string, mixed>>
 */
function calendarEntries(iterable $days): array
{
    $entries = [];

    foreach ($days as $day) {
        foreach ($day['posts'] as $entry) {
            $entries[$entry['id']] = [...$entry, 'date' => $day['date']];
        }
    }

    return $entries;
}

it('shows the current week of the workspace, Monday to Sunday', function () {
    $this->actingAs($this->owner)->get(route('calendar.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('calendar/Index')
            ->where('view', 'week')
            ->where('period', '2026-11-23')
            ->where('previous', '2026-11-16')
            ->where('next', '2026-11-30')
            ->where('today', '2026-11-24')
            ->has('days', 7)
            ->where('days.0', ['date' => '2026-11-23', 'in_period' => true, 'today' => false, 'posts' => []])
            ->where('days.1', ['date' => '2026-11-24', 'in_period' => true, 'today' => true, 'posts' => []])
            ->where('days.6.date', '2026-11-29')
            ->where('can.manage', true));
});

it('takes today from the clock of the workspace, not from UTC', function () {
    // 23:30 UTC on Tuesday is already Wednesday in Copenhagen and Johannesburg.
    $this->travelTo('2026-11-24 23:30:00');

    $this->actingAs($this->owner)->get(route('calendar.index'))
        ->assertInertia(fn (Assert $page) => $page->where('today', '2026-11-25'));

    // 22:30 UTC on Sunday is Monday of the next week in Johannesburg only.
    $this->travelTo('2026-11-29 22:30:00');

    $this->get(route('calendar.index'))
        ->assertInertia(fn (Assert $page) => $page->where('today', '2026-11-29')->where('period', '2026-11-23'));

    $this->workspace->update(['timezone' => 'Africa/Johannesburg']);

    $this->get(route('calendar.index'))
        ->assertInertia(fn (Assert $page) => $page->where('today', '2026-11-30')->where('period', '2026-11-30'));
});

it('puts each post on its day and time in the workspace timezone', function (string $timezone, ?string $week, string $utc, ?string $date, ?string $time) {
    $this->workspace->update(['timezone' => $timezone]);
    $post = postAt($this->workspace, PostState::Scheduled, [$this->channel], $utc);
    setBody($post, 'We open at eight.');

    $this->actingAs($this->owner)->get(route('calendar.index', array_filter(['date' => $week])))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('days', 7)
            ->where('days', function ($days) use ($post, $date, $time) {
                $entry = calendarEntries($days)[$post->id] ?? null;

                return $date === null
                    ? $entry === null
                    : $entry === [
                        'id' => $post->id,
                        'state' => 'scheduled',
                        'time' => $time,
                        'channels' => ['Alpha'],
                        'excerpt' => 'We open at eight.',
                        'thumb_url' => null,
                        'image_count' => 0,
                        'date' => $date,
                    ];
            }));
})->with([
    'Copenhagen, past midnight' => ['Europe/Copenhagen', null, '2026-11-24 23:30:00', '2026-11-25', '00:30'],
    'Johannesburg, the same instant' => ['Africa/Johannesburg', null, '2026-11-24 23:30:00', '2026-11-25', '01:30'],
    'Copenhagen, first minute of the week' => ['Europe/Copenhagen', null, '2026-11-22 23:00:00', '2026-11-23', '00:00'],
    'Copenhagen, the minute before the week' => ['Europe/Copenhagen', null, '2026-11-22 22:59:00', null, null],
    'Copenhagen, last minute of the week' => ['Europe/Copenhagen', null, '2026-11-29 22:59:00', '2026-11-29', '23:59'],
    'Copenhagen, the minute after the week' => ['Europe/Copenhagen', null, '2026-11-29 23:00:00', null, null],
    'Johannesburg, first minute of the week' => ['Africa/Johannesburg', null, '2026-11-22 22:00:00', '2026-11-23', '00:00'],
    'Johannesburg, the minute before the week' => ['Africa/Johannesburg', null, '2026-11-22 21:59:00', null, null],
    'Johannesburg, last minute of the week' => ['Africa/Johannesburg', null, '2026-11-29 21:59:00', '2026-11-29', '23:59'],
    'Johannesburg, the minute after the week' => ['Africa/Johannesburg', null, '2026-11-29 22:00:00', null, null],
    'Copenhagen, the Sunday the clocks go forward' => ['Europe/Copenhagen', '2027-03-24', '2027-03-28 21:59:00', '2027-03-28', '23:59'],
    'Copenhagen, the Monday after the clocks went forward' => ['Europe/Copenhagen', '2027-03-24', '2027-03-28 22:00:00', null, null],
    'Copenhagen, the Sunday the clocks go back' => ['Europe/Copenhagen', '2027-10-27', '2027-10-31 22:59:00', '2027-10-31', '23:59'],
]);

it('shows scheduled, published and failed posts, plans as plans, and no rejected posts', function () {
    $scheduled = postAt($this->workspace, PostState::Scheduled, [$this->channel], '2026-11-25 08:00:00');
    $published = postAt($this->workspace, PostState::Published, [$this->channel], '2026-11-23 08:00:00');
    $failed = postAt($this->workspace, PostState::Failed, [$this->channel], '2026-11-23 12:00:00');
    $publishing = postAt($this->workspace, PostState::Publishing, [$this->channel], '2026-11-24 09:59:00');
    $draft = postAt($this->workspace, PostState::Draft, [$this->channel], '2026-11-26 12:00:00');
    $approved = postAt($this->workspace, PostState::Approved, [$this->channel], '2026-11-26 13:00:00');
    $rejected = postAt($this->workspace, PostState::Rejected, [$this->channel], '2026-11-26 14:00:00');
    $unplanned = postInState($this->workspace, PostState::Draft, [$this->channel]);

    $this->actingAs($this->owner)->get(route('calendar.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('days', function ($days) use ($scheduled, $published, $failed, $publishing, $draft, $approved, $rejected, $unplanned) {
                $entries = calendarEntries($days);

                expect(array_map(fn (array $entry): array => [$entry['date'], $entry['time'], $entry['state']], $entries))->toBe([
                    $published->id => ['2026-11-23', '09:00', 'published'],
                    $failed->id => ['2026-11-23', '13:00', 'failed'],
                    $publishing->id => ['2026-11-24', '10:59', 'publishing'],
                    $scheduled->id => ['2026-11-25', '09:00', 'scheduled'],
                    $draft->id => ['2026-11-26', '13:00', 'draft'],
                    $approved->id => ['2026-11-26', '14:00', 'approved'],
                ])
                    ->and($entries)->not->toHaveKey($rejected->id)
                    ->and($entries)->not->toHaveKey($unplanned->id);

                return true;
            }));
});

it('shows a month as whole weeks', function (string $date, string $period, int $days, string $first, string $last, string $previous, string $next) {
    $inside = postAt($this->workspace, PostState::Scheduled, [$this->channel], $period.' 11:00:00');

    $this->actingAs($this->owner)->get(route('calendar.index', ['view' => 'month', 'date' => $date]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('view', 'month')
            ->where('period', $period)
            ->where('previous', $previous)
            ->where('next', $next)
            ->has('days', $days)
            ->where('days.0.date', $first)
            ->where('days.'.($days - 1).'.date', $last)
            ->where('days', function ($all) use ($inside, $period) {
                $all = collect($all);

                // Days of the neighbouring months are marked as such.
                expect($all->where('in_period', true)->first()['date'])->toBe($period)
                    ->and($all->where('in_period', true)->count())->toBe((int) date('t', strtotime($period)))
                    ->and(calendarEntries($all)[$inside->id]['date'])->toBe($period);

                return true;
            }));
})->with([
    'November 2026 starts on a Sunday' => ['2026-11-24', '2026-11-01', 42, '2026-10-26', '2026-12-06', '2026-10-01', '2026-12-01'],
    'February 2027 is exactly four weeks' => ['2027-02-10', '2027-02-01', 28, '2027-02-01', '2027-02-28', '2027-01-01', '2027-03-01'],
    'March 2027 holds a clock change' => ['2027-03-31', '2027-03-01', 35, '2027-03-01', '2027-04-04', '2027-02-01', '2027-04-01'],
]);

it('moves by week and falls back to today for a date that is not one', function () {
    $this->actingAs($this->owner);

    $this->get(route('calendar.index', ['date' => '2026-12-03']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('period', '2026-11-30')
            ->where('previous', '2026-11-23')
            ->where('next', '2026-12-07')
            ->where('today', '2026-11-24')
            ->where('days.1.today', false));

    foreach (['2026-02-31', 'yesterday', '0001-01-01', ''] as $date) {
        $this->get(route('calendar.index', ['date' => $date]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('period', '2026-11-23'));
    }
});

it('lets every role look, and offers new posts to owners and editors only', function (Role $role, bool $manage) {
    $this->actingAs(userWithRole($this->workspace, $role))->get(route('calendar.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('can.manage', $manage));
})->with([
    'owner' => [Role::Owner, true],
    'editor' => [Role::Editor, true],
    'viewer' => [Role::Viewer, false],
]);

it('shows only the posts of the current workspace', function () {
    $mine = postAt($this->workspace, PostState::Scheduled, [$this->channel], '2026-11-25 08:00:00');
    $elsewhere = Workspace::factory()->create();
    postAt($elsewhere, PostState::Scheduled, [channelIn($elsewhere)], '2026-11-25 08:00:00');

    $this->actingAs($this->owner)->get(route('calendar.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('days', fn ($days) => array_keys(calendarEntries($days)) === [$mine->id]));
});

it('is closed to guests and to users without a workspace', function () {
    $this->get(route('calendar.index'))->assertRedirect(route('login'));

    $this->actingAs(User::factory()->create())->get(route('calendar.index'))->assertForbidden();
});
