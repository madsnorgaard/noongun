<?php

use App\Domain\Channels\Contracts\Metric;
use App\Domain\Content\PostState;
use App\Domain\Insights\ChannelPolling;
use App\Domain\Workspaces\Models\Workspace;
use App\Domain\Workspaces\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/../../Support/publishing.php';
require_once __DIR__.'/../../Support/posts.php';
require_once __DIR__.'/../../Support/insights.php';

beforeEach(function () {
    $this->travelTo('2026-11-26 10:00:00');
    Queue::fake();

    $this->fake = fakeMetricsAdapter();
    fakeChannelAdapter('linkedin');

    $this->workspace = Workspace::factory()->create(['timezone' => 'Europe/Copenhagen']);
    $this->owner = userWithRole($this->workspace, Role::Owner);
    $this->page = channelIn($this->workspace, ['display_name' => 'Signal Hill']);

    $this->insights = fn (array $query = []): array => $this->actingAs($this->owner)
        ->get(route('insights.index', $query))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('insights/Index'))
        ->inertiaProps();
});

describe('the period', function () {
    it('is the last 28 days unless the address names 7, 28 or 90', function (mixed $period, int $days, string $from) {
        $props = ($this->insights)($period === null ? [] : ['period' => $period]);

        expect($props['period'])->toBe([
            'days' => $days,
            'options' => [7, 28, 90],
            'from' => $from,
            'until' => '2026-11-26',
        ]);
    })->with([
        'nothing' => [null, 28, '2026-10-30'],
        'a week' => ['7', 7, '2026-11-20'],
        'four weeks' => ['28', 28, '2026-10-30'],
        'a quarter' => ['90', 90, '2026-08-29'],
        'a number that is not offered' => ['14', 28, '2026-10-30'],
        'not a number' => ['all', 28, '2026-10-30'],
        'a list' => [['7'], 28, '2026-10-30'],
    ]);

    it('counts days on the clock of the workspace: a post from 23:30 belongs to that day', function (string $timezone) {
        $this->workspace->update(['timezone' => $timezone]);
        // What the clock of the workspace shows, as the instant it is stored as.
        $local = fn (string $time) => CarbonImmutable::parse($time, $timezone)->utc();

        $this->travelTo($local('2026-11-26 23:45'));

        $lastDay = publishedVariant($this->workspace, $this->page, $local('2026-11-26 23:30'), body: 'Late on the last day');
        $firstDay = publishedVariant($this->workspace, $this->page, $local('2026-11-20 00:10'), body: 'Early on the first day');
        $dayBefore = publishedVariant($this->workspace, $this->page, $local('2026-11-19 23:30'), body: 'Late on the day before');

        foreach ([$lastDay, $firstDay, $dayBefore] as $variant) {
            snapshotFor($variant, ['reactions' => 1]);
        }

        $props = ($this->insights)(['period' => 7]);

        expect($props['period'])->toMatchArray(['from' => '2026-11-20', 'until' => '2026-11-26'])
            ->and($props['totals']['posts'])->toBe(2)
            ->and(array_column($props['top'], 'excerpt'))->toEqualCanonicalizing(['Late on the last day', 'Early on the first day']);

        // Forty minutes later it is the next day there, and the first day
        // of the period has moved on with it.
        $this->travelTo($local('2026-11-27 00:25'));

        $next = ($this->insights)(['period' => 7]);

        expect($next['period'])->toMatchArray(['from' => '2026-11-21', 'until' => '2026-11-27'])
            ->and(array_column($next['top'], 'excerpt'))->toBe(['Late on the last day']);
    })->with(['Europe/Copenhagen', 'Africa/Johannesburg']);
});

describe('the pillars', function () {
    it('gives totals and honest averages when only some posts have a number', function () {
        $pillar = pillarIn($this->workspace, ['name' => 'Harbour']);

        $withReach = publishedVariant($this->workspace, $this->page, now()->subDay(), $pillar);
        $withoutReach = publishedVariant($this->workspace, $this->page, now()->subDays(2), $pillar);
        publishedVariant($this->workspace, $this->page, now()->subDays(3), $pillar);

        snapshotFor($withReach, ['reactions' => 10, 'comments' => 2, 'shares' => 1, 'reach' => 900]);
        snapshotFor($withoutReach, ['reactions' => 21, 'comments' => 0, 'shares' => 0]);

        $props = ($this->insights)();

        expect($props['pillars'])->toHaveCount(1)
            ->and($props['pillars'][0])->toEqual([
                'id' => $pillar->id,
                'name' => 'Harbour',
                'posts' => 3,
                'measured_posts' => 2,
                'scored_posts' => 2,
                'numbers' => [
                    'reactions' => ['total' => 31, 'average' => 15.5, 'posts' => 2],
                    'comments' => ['total' => 2, 'average' => 1, 'posts' => 2],
                    'shares' => ['total' => 1, 'average' => 0.5, 'posts' => 2],
                    'clicks' => ['total' => null, 'average' => null, 'posts' => 0],
                    // Over the one post that has reach, not over the two measured.
                    'reach' => ['total' => 900, 'average' => 900, 'posts' => 1],
                ],
                'score' => 38,
                'average_score' => 19,
            ])
            ->and($props['totals'])->toEqual([
                'posts' => 3,
                'measured_posts' => 2,
                'numbers' => ['reactions' => 31, 'comments' => 2, 'shares' => 1, 'clicks' => null, 'reach' => 900],
                'score' => 38,
            ])
            ->and($props['status'])->toBe('ok');
    });

    it('sorts by average score, pillars without a score after those, and posts without a pillar last', function () {
        $many = pillarIn($this->workspace, ['name' => 'Many small']);
        $few = pillarIn($this->workspace, ['name' => 'Few strong']);
        $quiet = pillarIn($this->workspace, ['name' => 'Quiet']);
        $alsoQuiet = pillarIn($this->workspace, ['name' => 'Also quiet']);
        pillarIn($this->workspace, ['name' => 'Unused']);

        foreach (range(1, 3) as $day) {
            snapshotFor(publishedVariant($this->workspace, $this->page, now()->subDays($day), $many), ['reactions' => 10]);
        }

        snapshotFor(publishedVariant($this->workspace, $this->page, now()->subDay(), $few), ['reactions' => 20]);
        publishedVariant($this->workspace, $this->page, now()->subDay(), $quiet);
        publishedVariant($this->workspace, $this->page, now()->subDay(), $alsoQuiet);
        snapshotFor(publishedVariant($this->workspace, $this->page, now()->subDay()), ['reactions' => 500]);

        $pillars = ($this->insights)()['pillars'];

        expect(array_map(fn (array $pillar) => [$pillar['name'], $pillar['score'], $pillar['average_score']], $pillars))->toEqual([
            ['Few strong', 20, 20],
            ['Many small', 30, 10],
            ['Also quiet', null, null],
            ['Quiet', null, null],
            [null, 500, 500],
        ]);
    });
});

describe('the top posts', function () {
    it('lists the best posts of the period with channel, pillar, first line and numbers', function () {
        $pillar = pillarIn($this->workspace, ['name' => 'Harbour']);
        $second = channelIn($this->workspace, ['display_name' => 'Lion Battery']);

        $best = publishedVariant($this->workspace, $this->page, now()->subDays(3), $pillar, "The gun fired at noon.\n\nAs it has since 1806.");
        $bestElsewhere = alsoPublishedOn($this->workspace, $best, $second, now()->subDays(2));
        $tiedOlder = publishedVariant($this->workspace, $this->page, now()->subDays(2), body: 'Tied, older');
        $tiedNewer = publishedVariant($this->workspace, $this->page, now()->subDay(), body: 'Tied, newer');
        $reachOnly = publishedVariant($this->workspace, $this->page, now()->subDay(), body: 'Reach only');
        publishedVariant($this->workspace, $this->page, now()->subDay(), body: 'Not measured');
        $tooOld = publishedVariant($this->workspace, $this->page, now()->subDays(40), body: 'Too old');

        snapshotFor($best, ['reactions' => 6, 'reach' => 200]);
        snapshotFor($bestElsewhere, ['reactions' => 6]);
        snapshotFor($tiedOlder, ['reactions' => 10]);
        snapshotFor($tiedNewer, ['comments' => 5]);
        snapshotFor($reachOnly, ['reach' => 9000]);
        snapshotFor($tooOld, ['reactions' => 999]);

        $top = ($this->insights)()['top'];

        expect(array_column($top, 'excerpt'))->toBe(['The gun fired at noon.', 'Tied, newer', 'Tied, older'])
            ->and($top[0])->toEqual([
                'post_id' => $best->post_id,
                'excerpt' => 'The gun fired at noon.',
                'pillar' => 'Harbour',
                'channels' => [
                    ['name' => 'Signal Hill', 'platform_label' => 'Facebook'],
                    ['name' => 'Lion Battery', 'platform_label' => 'Facebook'],
                ],
                'published_at' => '2026-11-24T10:00:00+00:00',
                'numbers' => ['reactions' => 12, 'comments' => null, 'shares' => null, 'clicks' => null, 'reach' => 200],
                'score' => 12,
                'measured' => 2,
                'published' => 2,
            ])
            ->and($top[1]['pillar'])->toBeNull();
    });

    it('shows at most ten', function () {
        foreach (range(1, 12) as $index) {
            snapshotFor(publishedVariant($this->workspace, $this->page, now()->subHours($index)), ['reactions' => $index]);
        }

        $top = ($this->insights)()['top'];

        expect($top)->toHaveCount(10)->and($top[0]['score'])->toBe(12);
    });
});

describe('the channels', function () {
    it('lists every channel with what it added up to, and why a channel has no numbers', function () {
        $this->fake->channelsMayRead([Metric::Reactions, Metric::Comments, Metric::Shares]);

        $profile = channelIn($this->workspace, ['platform' => 'linkedin', 'display_name' => 'Thandi']);
        $stale = channelIn($this->workspace, ['display_name' => 'Old Page', 'status' => 'needs_reconnect']);
        $gone = channelIn($this->workspace, ['display_name' => 'Gone with posts', 'status' => 'disconnected']);
        channelIn($this->workspace, ['display_name' => 'Gone without posts', 'status' => 'disconnected']);
        channelIn($this->workspace, ['display_name' => 'Not confirmed', 'status' => 'pending']);
        $idle = channelIn($this->workspace, ['display_name' => 'Zebra idle']);

        $onPage = publishedVariant($this->workspace, $this->page, now()->subDay());
        alsoPublishedOn($this->workspace, $onPage, $profile);
        publishedVariant($this->workspace, $this->page, now()->subDays(2));
        snapshotFor($onPage, ['reactions' => 4, 'comments' => 1]);
        snapshotFor(publishedVariant($this->workspace, $gone, now()->subDays(3)), ['reactions' => 2]);

        ChannelPolling::pause($stale->id, 600);

        $channels = collect(($this->insights)()['channels'])->keyBy('name');

        expect($channels->keys()->all())->toBe(['Gone with posts', 'Old Page', 'Signal Hill', 'Zebra idle', 'Thandi'])
            ->and($channels['Signal Hill'])->toMatchArray([
                'id' => $this->page->id,
                'platform_label' => 'Facebook',
                'status' => 'active',
                'gives_numbers' => true,
                'metrics' => ['reactions', 'comments', 'shares'],
                'needs_permission' => ['clicks', 'reach'],
                'paused' => false,
                'posts' => 2,
                'measured_posts' => 1,
                'score' => 6,
                'average_score' => 6,
            ])
            ->and($channels['Signal Hill']['numbers']['reactions'])->toEqual(['total' => 4, 'average' => 4, 'posts' => 1])
            ->and($channels['Thandi'])->toMatchArray([
                'platform_label' => 'LinkedIn',
                'gives_numbers' => false,
                'metrics' => [],
                'needs_permission' => [],
                'paused' => false,
                'posts' => 1,
                'measured_posts' => 0,
                'score' => null,
            ])
            ->and($channels['Thandi']['numbers']['reactions'])->toBe(['total' => null, 'average' => null, 'posts' => 0])
            ->and($channels['Old Page'])->toMatchArray(['status' => 'needs_reconnect', 'paused' => true, 'posts' => 0])
            ->and($channels['Gone with posts'])->toMatchArray(['status' => 'disconnected', 'posts' => 1, 'measured_posts' => 1])
            ->and($channels['Zebra idle'])->toMatchArray(['id' => $idle->id, 'posts' => 0, 'measured_posts' => 0, 'score' => null]);
    });
});

describe('what the page says when there is little to show', function () {
    it('says that nothing is published yet', function () {
        postInState($this->workspace, PostState::Scheduled, [$this->page], now()->addDay());

        $props = ($this->insights)();

        expect($props['status'])->toBe('nothing_published')
            ->and($props['totals'])->toBe(['posts' => 0, 'measured_posts' => 0, 'numbers' => array_fill_keys(['reactions', 'comments', 'shares', 'clicks', 'reach'], null), 'score' => null])
            ->and($props['pillars'])->toBe([])
            ->and($props['top'])->toBe([])
            ->and(array_column($props['channels'], 'name'))->toBe(['Signal Hill']);
    });

    it('says that nothing was published in this period when older posts exist', function () {
        snapshotFor(publishedVariant($this->workspace, $this->page, now()->subDays(40)), ['reactions' => 3]);

        expect(($this->insights)(['period' => 28])['status'])->toBe('nothing_in_period')
            ->and(($this->insights)(['period' => 90])['status'])->toBe('ok');
    });

    it('says that nothing is measured yet, without inventing zeros', function () {
        publishedVariant($this->workspace, $this->page, now()->subMinutes(5), pillarIn($this->workspace, ['name' => 'Harbour']));

        $props = ($this->insights)();

        expect($props['status'])->toBe('nothing_measured')
            ->and($props['totals'])->toMatchArray(['posts' => 1, 'measured_posts' => 0, 'score' => null])
            ->and($props['pillars'][0])->toMatchArray(['name' => 'Harbour', 'posts' => 1, 'measured_posts' => 0, 'score' => null, 'average_score' => null])
            ->and($props['pillars'][0]['numbers']['reactions'])->toBe(['total' => null, 'average' => null, 'posts' => 0])
            ->and($props['top'])->toBe([]);
    });

    it('says that insights are turned off on this instance, and still shows what was read before', function () {
        config(['noongun.insights.enabled' => false]);

        expect(($this->insights)())->toMatchArray(['status' => 'nothing_published'])
            ->and(($this->insights)()['reading']['enabled'])->toBeFalse();

        snapshotFor(publishedVariant($this->workspace, $this->page, now()->subDay()), ['reactions' => 3]);

        $props = ($this->insights)();

        expect($props['reading']['enabled'])->toBeFalse()
            ->and($props['status'])->toBe('ok')
            ->and($props['totals']['numbers']['reactions'])->toBe(3);
    });
});

describe('how the score is explained', function () {
    it('gives the weights of the configuration and the cadence in words', function () {
        config(['noongun.insights.score' => ['reactions' => 1, 'comments' => '2.5', 'shares' => 4, 'clicks' => 0]]);

        $props = ($this->insights)();

        expect($props['weights'])->toEqual(['reactions' => 1, 'comments' => 2.5, 'shares' => 4, 'clicks' => 0])
            ->and($props['reading'])->toBe([
                'enabled' => true,
                'cadence' => 'A post is read every hour until it is 1 day old, then once a day until it is 21 days old, and not after that.',
                'stop_after' => '21 days',
            ]);
    });
});

describe('who may see the page', function () {
    it('shows it to a viewer', function () {
        snapshotFor(publishedVariant($this->workspace, $this->page, now()->subDay()), ['reactions' => 3]);

        $this->actingAs(userWithRole($this->workspace, Role::Viewer))
            ->get(route('insights.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('totals.numbers.reactions', 3)->has('top', 1));
    });

    it('sends a guest to the login page and refuses someone without a workspace', function () {
        $this->get(route('insights.index'))->assertRedirect(route('login'));

        $this->actingAs(User::factory()->create())->get(route('insights.index'))->assertForbidden();
    });

    it('changes nothing: the page only answers to GET', function () {
        $this->actingAs($this->owner)->post('/insights')->assertStatus(405);
    });

    it('never shows the posts, pillars, channels or numbers of another workspace', function () {
        $other = Workspace::factory()->create();
        $foreignChannel = channelIn($other, ['display_name' => 'Foreign Page']);
        $foreignPillar = pillarIn($other, ['name' => 'Foreign pillar']);
        $foreign = publishedVariant($other, $foreignChannel, now()->subDay(), $foreignPillar, 'Foreign text');
        snapshotFor($foreign, ['reactions' => 999, 'reach' => 99999]);

        $own = publishedVariant($this->workspace, $this->page, now()->subDay(), body: 'Own text');
        snapshotFor($own, ['reactions' => 2]);

        $props = ($this->insights)(['period' => 90]);

        expect(json_encode($props))->not->toContain('Foreign')->not->toContain('999')
            ->and($props['totals'])->toMatchArray(['posts' => 1, 'measured_posts' => 1])
            ->and(array_column($props['pillars'], 'name'))->toBe([null])
            ->and(array_column($props['top'], 'post_id'))->toBe([$own->post_id])
            ->and(array_column($props['channels'], 'id'))->toBe([$this->page->id]);

        // And the other way round: the other workspace sees only its own.
        $theirs = $this->actingAs(userWithRole($other, Role::Owner))->get(route('insights.index'))->inertiaProps();

        expect(array_column($theirs['top'], 'post_id'))->toBe([$foreign->post_id])
            ->and(array_column($theirs['pillars'], 'name'))->toBe(['Foreign pillar']);
    });
});

describe('how much the page reads', function () {
    it('makes the same number of queries for fifty posts as for five', function () {
        $pillars = [pillarIn($this->workspace, ['name' => 'Harbour']), pillarIn($this->workspace, ['name' => 'Hill']), null];
        $second = channelIn($this->workspace, ['display_name' => 'Lion Battery']);

        $publish = function (int $count) use ($pillars, $second): void {
            foreach (range(1, $count) as $index) {
                $variant = publishedVariant($this->workspace, $this->page, now()->subHours($index), $pillars[$index % 3]);
                snapshotFor($variant, ['reactions' => $index, 'comments' => $index % 4]);

                if ($index % 2 === 0) {
                    snapshotFor(alsoPublishedOn($this->workspace, $variant, $second), ['reactions' => 1]);
                }
            }
        };

        $queries = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();

            $props = ($this->insights)();
            $count = count(DB::getQueryLog());

            DB::disableQueryLog();

            expect($props['top'])->not->toBe([]);

            return $count;
        };

        $publish(5);
        $withFive = $queries();

        $publish(45);
        $withFifty = $queries();

        expect(($this->insights)()['totals']['posts'])->toBe(50)
            ->and($withFifty)->toBe($withFive)
            ->and($withFifty)->toBeLessThanOrEqual(15);
    });
});

describe('the line on the dashboard', function () {
    it('is not there when nothing of the last week is measured', function () {
        publishedVariant($this->workspace, $this->page, now()->subDay());
        snapshotFor(publishedVariant($this->workspace, $this->page, now()->subDays(10)), ['reactions' => 3]);

        expect($this->actingAs($this->owner)->get(route('dashboard'))->assertOk()->inertiaProps('insights'))->toBeNull();
    });

    it('gives the totals of the last seven days and the best post', function () {
        $best = publishedVariant($this->workspace, $this->page, now()->subDays(2), body: 'The best of the week');
        $other = publishedVariant($this->workspace, $this->page, now()->subDay(), body: 'Another one');
        publishedVariant($this->workspace, $this->page, now()->subHour());
        $before = publishedVariant($this->workspace, $this->page, now()->subDays(9), body: 'Before the week');

        snapshotFor($best, ['reactions' => 8, 'shares' => 1]);
        snapshotFor($other, ['reactions' => 2]);
        snapshotFor($before, ['reactions' => 500]);

        $foreign = publishedVariant(Workspace::factory()->create(), null, now()->subDay(), body: 'Foreign text');
        snapshotFor($foreign, ['reactions' => 999]);

        $insights = $this->actingAs(userWithRole($this->workspace, Role::Viewer))->get(route('dashboard'))->inertiaProps('insights');

        expect($insights)->toMatchArray([
            'days' => 7,
            'posts' => 3,
            'measured_posts' => 2,
            'numbers' => ['reactions' => 10, 'comments' => null, 'shares' => 1, 'clicks' => null, 'reach' => null],
            'score' => 13,
        ])
            ->and($insights['best'])->toMatchArray(['post_id' => $best->post_id, 'excerpt' => 'The best of the week', 'score' => 11]);
    });

    it('is not there for someone without a workspace', function () {
        expect($this->actingAs(User::factory()->create())->get(route('dashboard'))->assertOk()->inertiaProps('insights'))->toBeNull();
    });
});
