<?php

use App\Domain\Channels\Contracts\Metric;
use App\Domain\Content\PostState;
use App\Domain\Insights\ChannelPolling;
use App\Domain\Workspaces\Models\Workspace;
use App\Domain\Workspaces\Role;
use App\Http\Presenters\InsightsPresenter;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
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

    // A measured post that is old enough to be compared.
    $this->measured = function (array $numbers, int $daysAgo = 3, $pillar = null, $channel = null, ?string $body = null) {
        $variant = publishedVariant($this->workspace, $channel ?? $this->page, now()->subDays($daysAgo), $pillar, $body);
        snapshotFor($variant, $numbers);

        return $variant;
    };
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
        $channelPosts = fn (array $props) => collect($props['channels'])->firstWhere('id', $this->page->id)['channel_posts'];

        $this->travelTo($local('2026-11-26 23:45'));

        publishedVariant($this->workspace, $this->page, $local('2026-11-26 23:30'));
        publishedVariant($this->workspace, $this->page, $local('2026-11-20 00:10'));
        publishedVariant($this->workspace, $this->page, $local('2026-11-19 23:30'));

        $props = ($this->insights)(['period' => 7]);

        // The last day and the first day are in, the evening before is not.
        expect($props['period'])->toMatchArray(['from' => '2026-11-20', 'until' => '2026-11-26'])
            ->and($props['totals']['posts'])->toBe(2)
            ->and($channelPosts($props))->toBe(2);

        // Forty minutes later it is the next day there, and the first day
        // of the period has moved on with it.
        $this->travelTo($local('2026-11-27 00:25'));

        $next = ($this->insights)(['period' => 7]);

        expect($next['period'])->toMatchArray(['from' => '2026-11-21', 'until' => '2026-11-27'])
            ->and($next['totals']['posts'])->toBe(1);
    })->with(['Europe/Copenhagen', 'Africa/Johannesburg']);
});

describe('the totals of the period', function () {
    it('adds up what can be added, says what a partial total is made of, and never adds up reach', function () {
        $second = channelIn($this->workspace, ['display_name' => 'Lion Battery']);

        $withAll = ($this->measured)(['reactions' => 10, 'comments' => 2, 'shares' => 1, 'clicks' => 40, 'reach' => 900]);
        snapshotFor(alsoPublishedOn($this->workspace, $withAll, $second), ['reactions' => 5, 'reach' => 400]);
        ($this->measured)(['reactions' => 21, 'comments' => 0, 'shares' => 0]);
        publishedVariant($this->workspace, $this->page, now()->subMinutes(5));

        $props = ($this->insights)();

        expect($props['status'])->toBe('ok')
            ->and($props['totals'])->toEqual([
                'posts' => 3,
                'channel_posts' => 4,
                'measured' => 3,
                'numbers' => ['reactions' => 36, 'comments' => 2, 'shares' => 1, 'clicks' => 40, 'reach' => null],
                // The clicks are those of one of the three measured.
                'with' => ['reactions' => 3, 'comments' => 2, 'shares' => 2, 'clicks' => 1, 'reach' => 2],
                // 36 + 2 x 2 + 1 x 3: the clicks are not in it.
                'score' => 43,
            ]);
    });
});

describe('the pillars', function () {
    it('gives the average of one post on one channel, over the posts that have the number', function () {
        $pillar = pillarIn($this->workspace, ['name' => 'Harbour']);

        ($this->measured)(['reactions' => 10, 'comments' => 2, 'shares' => 1, 'clicks' => 40, 'reach' => 900], 3, $pillar);
        ($this->measured)(['reactions' => 21, 'comments' => 0, 'shares' => 0], 4, $pillar);
        publishedVariant($this->workspace, $this->page, now()->subDays(5), $pillar);

        $props = ($this->insights)();

        expect($props['pillars'])->toHaveCount(1)
            ->and($props['pillars'][0])->toEqual([
                'id' => $pillar->id,
                'name' => 'Harbour',
                'enough' => false,
                'posts' => 3,
                'channel_posts' => 3,
                'measured' => 2,
                'scored' => 2,
                'numbers' => [
                    'reactions' => ['total' => 31, 'average' => 15.5, 'with' => 2],
                    'comments' => ['total' => 2, 'average' => 1, 'with' => 2],
                    'shares' => ['total' => 1, 'average' => 0.5, 'with' => 2],
                    'clicks' => ['total' => 40, 'average' => 40, 'with' => 1],
                    // Reach has an average and no total; over the one post that has it.
                    'reach' => ['total' => null, 'average' => 900, 'with' => 1],
                ],
                'score' => 38,
                'average_score' => 19,
            ]);
    });

    it('does not let a pillar look better because it went to more channels, and compares inside one channel when asked', function () {
        $wide = pillarIn($this->workspace, ['name' => 'On both Pages']);
        $narrow = pillarIn($this->workspace, ['name' => 'On the big Page']);
        $small = channelIn($this->workspace, ['display_name' => 'Small Page']);

        $both = ($this->measured)(['reactions' => 100], 3, $wide);
        snapshotFor(alsoPublishedOn($this->workspace, $both, $small), ['reactions' => 10]);
        ($this->measured)(['reactions' => 90], 3, $narrow);

        $rows = fn (array $query) => array_map(
            fn (array $pillar) => [$pillar['name'], $pillar['channel_posts'], $pillar['average_score']],
            ($this->insights)($query)['pillars'],
        );

        // Together: (100 + 10) / 2 = 55 for one post on one channel, not 110.
        expect($rows([]))->toEqual([['On the big Page', 1, 90], ['On both Pages', 2, 55]])
            // On the big Page alone the order turns: like with like.
            ->and($rows(['channel' => $this->page->id]))->toEqual([['On both Pages', 1, 100], ['On the big Page', 1, 90]])
            ->and($rows(['channel' => $small->id]))->toEqual([['On both Pages', 1, 10]])
            ->and(($this->insights)(['channel' => $small->id])['channel'])->toBe($small->id);
    });

    it('ignores a channel in the address that is not one of this workspace', function (mixed $channel) {
        ($this->measured)(['reactions' => 3]);

        $props = ($this->insights)(['channel' => $channel]);

        expect($props['channel'])->toBeNull()->and($props['pillars'])->toHaveCount(1);
    })->with(['999999', 'abc', '-1', fn () => (string) channelIn(Workspace::factory()->create())->id]);

    it('leaves posts that are too young out of the comparison and still counts them as published', function () {
        $pillar = pillarIn($this->workspace, ['name' => 'Harbour']);

        ($this->measured)(['reactions' => 20], 3, $pillar);
        snapshotFor(publishedVariant($this->workspace, $this->page, now()->subHours(3), $pillar), ['reactions' => 0]);

        expect(($this->insights)()['pillars'][0])->toMatchArray(['posts' => 2, 'measured' => 1, 'average_score' => 20]);

        config(['noongun.insights.min_age_hours' => 1]);

        expect(($this->insights)()['pillars'][0])->toMatchArray(['posts' => 2, 'measured' => 2, 'average_score' => 10]);
    });

    it('lists pillars with enough posts first by average score, then those with too few, and posts without a pillar last', function () {
        config(['noongun.insights.min_measured_posts' => 3]);

        $steady = pillarIn($this->workspace, ['name' => 'Steady']);
        $strong = pillarIn($this->workspace, ['name' => 'Strong']);
        $lucky = pillarIn($this->workspace, ['name' => 'One lucky post']);
        $quiet = pillarIn($this->workspace, ['name' => 'Quiet']);
        pillarIn($this->workspace, ['name' => 'Unused']);

        foreach (range(3, 5) as $day) {
            ($this->measured)(['reactions' => 10], $day, $steady);
            ($this->measured)(['reactions' => 20], $day, $strong);
            ($this->measured)(['reactions' => 500], $day);
        }

        ($this->measured)(['reactions' => 900], 3, $lucky);
        publishedVariant($this->workspace, $this->page, now()->subDays(3), $quiet);

        expect(array_map(fn (array $pillar) => [$pillar['name'], $pillar['enough'], $pillar['average_score']], ($this->insights)()['pillars']))->toEqual([
            ['Strong', true, 20],
            ['Steady', true, 10],
            ['One lucky post', false, 900],
            ['Quiet', false, null],
            [null, true, 500],
        ]);
    });
});

describe('the top posts', function () {
    it('lists the posts of the period by comparable score with channel, pillar, first line and numbers', function () {
        config(['noongun.insights.min_measured_posts' => 3]);

        $pillar = pillarIn($this->workspace, ['name' => 'Harbour']);
        $second = channelIn($this->workspace, ['display_name' => 'Signal Hill']);

        $best = ($this->measured)(['reactions' => 6, 'reach' => 200], 4, $pillar, null, "The gun fired at noon.\n\nAs it has since 1806.");
        snapshotFor(alsoPublishedOn($this->workspace, $best, $second, now()->subDays(3)), ['reactions' => 6, 'reach' => 150]);
        ($this->measured)(['reactions' => 10], 5, null, null, 'Tied, older');
        ($this->measured)(['comments' => 5], 3, null, null, 'Tied, newer');
        ($this->measured)(['reactions' => 1, 'clicks' => 4000, 'reach' => 700], 3, null, null, 'Many clicks');
        ($this->measured)(['clicks' => 50, 'reach' => 9000], 3, null, null, 'Clicks and reach only');
        ($this->measured)(['reactions' => 999], 40, null, null, 'Before the period');
        snapshotFor(publishedVariant($this->workspace, $this->page, now()->subHours(5), body: 'Too young'), ['reactions' => 800]);
        publishedVariant($this->workspace, $this->page, now()->subDays(3), body: 'Not measured');

        $props = ($this->insights)();
        $top = $props['top'];

        expect($props['ranked'])->toBeTrue()
            ->and(array_column($top, 'excerpt'))->toBe(['The gun fired at noon.', 'Tied, newer', 'Tied, older', 'Many clicks'])
            ->and($top[0])->toEqual([
                'post_id' => $best->post_id,
                'excerpt' => 'The gun fired at noon.',
                'pillar' => 'Harbour',
                // Two channels with the same name are told apart by id.
                'channels' => [
                    ['id' => $this->page->id, 'name' => 'Signal Hill', 'platform_label' => 'Facebook'],
                    ['id' => $second->id, 'name' => 'Signal Hill', 'platform_label' => 'Facebook'],
                ],
                'published_at' => '2026-11-23T10:00:00+00:00',
                // On two channels: reach is not added up.
                'numbers' => ['reactions' => 12, 'comments' => null, 'shares' => null, 'clicks' => null, 'reach' => null],
                'score' => 12,
                'measured' => 2,
                'published' => 2,
            ])
            // On one channel: reach is that of the post, clicks are shown and not scored.
            ->and($top[3])->toMatchArray(['score' => 1, 'numbers' => ['reactions' => 1, 'comments' => null, 'shares' => null, 'clicks' => 4000, 'reach' => 700]])
            ->and($top[1]['pillar'])->toBeNull();

        $onSecond = ($this->insights)(['channel' => $second->id]);

        expect($onSecond['ranked'])->toBeFalse()
            ->and($onSecond['top'])->toHaveCount(1)
            ->and($onSecond['top'][0])->toMatchArray(['post_id' => $best->post_id, 'score' => 6, 'measured' => 1, 'published' => 1])
            ->and($onSecond['top'][0]['numbers']['reach'])->toBe(150)
            ->and(array_column($onSecond['top'][0]['channels'], 'id'))->toBe([$second->id]);
    });

    it('makes no claim about a best post while fewer posts compete than the configured minimum', function () {
        foreach (range(1, 4) as $index) {
            ($this->measured)(['reactions' => $index], 3);
        }

        $props = ($this->insights)();

        expect($props['ranked'])->toBeFalse()->and($props['top'])->toHaveCount(4)
            ->and($props['reading'])->toMatchArray(['min_measured' => 5, 'min_age' => '2 days']);

        ($this->measured)(['reactions' => 9], 3);

        expect(($this->insights)()['ranked'])->toBeTrue();
    });

    it('shows at most ten', function () {
        foreach (range(1, 12) as $index) {
            ($this->measured)(['reactions' => $index], 3);
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
        // Young posts count here: this table says what is being read.
        snapshotFor($onPage, ['reactions' => 4, 'comments' => 1, 'reach' => 300]);
        snapshotFor(publishedVariant($this->workspace, $gone, now()->subDays(3)), ['reactions' => 2]);

        ChannelPolling::pause($stale->id, 600);
        ChannelPolling::pause($idle->id, 600, ChannelPolling::PAUSE_RATE_LIMIT);
        ChannelPolling::pauseUntilConnectedAgain($this->page->id, 3600);

        $channels = collect(($this->insights)()['channels'])->keyBy('name');

        expect($channels->keys()->all())->toBe(['Gone with posts', 'Old Page', 'Signal Hill', 'Zebra idle', 'Thandi'])
            ->and($channels['Signal Hill'])->toMatchArray([
                'id' => $this->page->id,
                'platform_label' => 'Facebook',
                'status' => 'active',
                'gives_numbers' => true,
                'metrics' => ['reactions', 'comments', 'shares'],
                'needs_permission' => ['clicks', 'reach'],
                // Facebook did not accept its token for reading.
                'pause' => ['kind' => 'token', 'until' => '2026-11-26T11:00:00+00:00'],
                'posts' => 2,
                'channel_posts' => 2,
                'measured' => 1,
                'score' => 6,
                'average_score' => 6,
            ])
            ->and($channels['Signal Hill']['numbers']['reactions'])->toEqual(['total' => 4, 'average' => 4, 'with' => 1])
            ->and($channels['Signal Hill']['numbers']['reach'])->toEqual(['total' => null, 'average' => 300, 'with' => 1])
            ->and($channels['Thandi'])->toMatchArray([
                'platform_label' => 'LinkedIn',
                'gives_numbers' => false,
                'metrics' => [],
                'needs_permission' => [],
                'pause' => null,
                'posts' => 1,
                'measured' => 0,
                'score' => null,
            ])
            ->and($channels['Thandi']['numbers']['reactions'])->toBe(['total' => null, 'average' => null, 'with' => 0])
            // A pause is said of a channel that would be read. This one
            // has to be connected again first, and the page says that.
            ->and($channels['Old Page'])->toMatchArray(['status' => 'needs_reconnect', 'pause' => null, 'posts' => 0])
            ->and($channels['Gone with posts'])->toMatchArray(['status' => 'disconnected', 'posts' => 1, 'measured' => 1])
            ->and($channels['Zebra idle'])->toMatchArray(['id' => $idle->id, 'posts' => 0, 'measured' => 0, 'score' => null])
            // Facebook asked for a wait, and the page says until when.
            ->and($channels['Zebra idle']['pause'])->toBe(['kind' => 'rate_limit', 'until' => '2026-11-26T10:10:00+00:00']);
    });

    it('says nothing of a pause while insights are turned off', function () {
        config(['noongun.insights.enabled' => false]);
        ChannelPolling::pause($this->page->id, 600);

        expect(($this->insights)()['channels'][0]['pause'])->toBeNull();
    });
});

describe('what the page says when there is little to show', function () {
    it('says that nothing is published yet', function () {
        postInState($this->workspace, PostState::Scheduled, [$this->page], now()->addDay());

        $props = ($this->insights)();

        expect($props['status'])->toBe('nothing_published')
            ->and($props['totals'])->toMatchArray(['posts' => 0, 'channel_posts' => 0, 'measured' => 0, 'score' => null])
            ->and($props['totals']['numbers'])->toBe(array_fill_keys(['reactions', 'comments', 'shares', 'clicks', 'reach'], null))
            ->and($props['pillars'])->toBe([])
            ->and($props['top'])->toBe([])
            ->and($props['ranked'])->toBeFalse()
            ->and(array_column($props['channels'], 'name'))->toBe(['Signal Hill']);
    });

    it('says that nothing was published in this period when older posts exist', function () {
        ($this->measured)(['reactions' => 3], 40);

        expect(($this->insights)(['period' => 28])['status'])->toBe('nothing_in_period')
            ->and(($this->insights)(['period' => 90])['status'])->toBe('ok');
    });

    it('says that nothing is measured yet, without inventing zeros', function () {
        publishedVariant($this->workspace, $this->page, now()->subMinutes(5), pillarIn($this->workspace, ['name' => 'Harbour']));

        $props = ($this->insights)();

        expect($props['status'])->toBe('nothing_measured')
            ->and($props['totals'])->toMatchArray(['posts' => 1, 'measured' => 0, 'score' => null])
            ->and($props['pillars'])->toBe([])
            ->and($props['top'])->toBe([]);
    });

    it('lists no post when the measured ones are all too young to compare', function () {
        snapshotFor(publishedVariant($this->workspace, $this->page, now()->subHours(3), pillarIn($this->workspace, ['name' => 'Harbour'])), ['reactions' => 4]);

        $props = ($this->insights)();

        expect($props['status'])->toBe('ok')
            ->and($props['totals'])->toMatchArray(['measured' => 1, 'score' => 4])
            ->and($props['top'])->toBe([])
            ->and($props['pillars'][0])->toMatchArray(['posts' => 1, 'measured' => 0, 'average_score' => null]);
    });

    it('says that insights are turned off on this instance, and still shows what was read before', function () {
        config(['noongun.insights.enabled' => false]);

        expect(($this->insights)())->toMatchArray(['status' => 'nothing_published'])
            ->and(($this->insights)()['reading']['enabled'])->toBeFalse();

        ($this->measured)(['reactions' => 3]);

        $props = ($this->insights)();

        expect($props['reading']['enabled'])->toBeFalse()
            ->and($props['status'])->toBe('ok')
            ->and($props['totals']['numbers']['reactions'])->toBe(3);
    });
});

describe('how the numbers are explained', function () {
    it('gives the weights of the score that is shown, the cadence and what a comparison asks, in words', function () {
        config([
            'noongun.insights.score' => ['reactions' => 1, 'comments' => '2.5', 'shares' => 4, 'clicks' => 7],
            'noongun.insights.min_age_hours' => 36,
            'noongun.insights.min_measured_posts' => 8,
        ]);

        expect(($this->insights)()['reading'])->toEqual([
            'enabled' => true,
            'cadence' => 'A post is read every hour until it is 1 day old, then once a day until it is 21 days old, and not after that.',
            'stop_after' => '21 days',
            'once_within' => '365 days',
            'min_age' => '36 hours',
            'min_measured' => 8,
            // Clicks have a weight in the configuration and none here.
            'weights' => ['reactions' => 1, 'comments' => 2.5, 'shares' => 4],
            'can_set_up' => true,
        ]);
    });

    it('tells only someone who can connect channels how to change the set-up', function (Role $role, bool $canSetUp) {
        $this->actingAs(userWithRole($this->workspace, $role))
            ->get(route('insights.index'))
            ->assertInertia(fn ($page) => $page->where('reading.can_set_up', $canSetUp));
    })->with([[Role::Owner, true], [Role::Editor, false], [Role::Viewer, false]]);

    it('expects the first reading as long after publishing as the scheduler waits between two runs', function () {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event) => str_contains((string) $event->command, 'noongun:poll-metrics'));

        expect($event)->not->toBeNull()
            ->and($event->expression)->toBe('*/'.InsightsPresenter::POLL_MINUTES.' * * * *');
    });
});

describe('who may see the page', function () {
    it('shows it to a viewer', function () {
        ($this->measured)(['reactions' => 3]);

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
        $foreign = publishedVariant($other, $foreignChannel, now()->subDays(3), $foreignPillar, 'Foreign text');
        snapshotFor($foreign, ['reactions' => 999, 'reach' => 99999]);

        $own = ($this->measured)(['reactions' => 2], 3, null, null, 'Own text');

        foreach ([[], ['channel' => $foreignChannel->id]] as $query) {
            $props = ($this->insights)(['period' => 90, ...$query]);

            expect(json_encode($props))->not->toContain('Foreign')->not->toContain('999')
                ->and($props['channel'])->toBeNull()
                ->and($props['totals'])->toMatchArray(['posts' => 1, 'measured' => 1])
                ->and(array_column($props['pillars'], 'name'))->toBe([null])
                ->and(array_column($props['top'], 'post_id'))->toBe([$own->post_id])
                ->and(array_column($props['channels'], 'id'))->toBe([$this->page->id]);
        }

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
                $variant = publishedVariant($this->workspace, $this->page, now()->subHours(50 + $index), $pillars[$index % 3]);
                snapshotFor($variant, ['reactions' => $index, 'comments' => $index % 4]);

                if ($index % 2 === 0) {
                    snapshotFor(alsoPublishedOn($this->workspace, $variant, $second), ['reactions' => 1]);
                }
            }
        };

        $queries = function (array $query = []): int {
            DB::flushQueryLog();
            DB::enableQueryLog();

            $props = ($this->insights)($query);
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
            ->and($queries(['channel' => $second->id]))->toBe($withFive)
            ->and($withFifty)->toBeLessThanOrEqual(16);
    });
});

describe('the block on the dashboard', function () {
    it('is not there when nothing of the last week is measured', function () {
        publishedVariant($this->workspace, $this->page, now()->subDay());
        ($this->measured)(['reactions' => 3], 10);

        expect($this->actingAs($this->owner)->get(route('dashboard'))->assertOk()->inertiaProps('insights'))->toBeNull();
    });

    it('gives the totals of the last seven days, without reach and without a best post while too few compete', function () {
        ($this->measured)(['reactions' => 8, 'shares' => 1, 'clicks' => 30, 'reach' => 600], 3, null, null, 'A good one');
        ($this->measured)(['reactions' => 2, 'reach' => 100], 4);
        publishedVariant($this->workspace, $this->page, now()->subHour());
        ($this->measured)(['reactions' => 500], 9);

        $foreign = publishedVariant(Workspace::factory()->create(), null, now()->subDays(3), body: 'Foreign text');
        snapshotFor($foreign, ['reactions' => 999]);

        $insights = $this->actingAs(userWithRole($this->workspace, Role::Viewer))->get(route('dashboard'))->inertiaProps('insights');

        expect($insights)->toEqual([
            'days' => 7,
            'posts' => 3,
            'channel_posts' => 3,
            'measured' => 2,
            'numbers' => ['reactions' => 10, 'comments' => null, 'shares' => 1, 'clicks' => 30, 'reach' => null],
            'with' => ['reactions' => 2, 'comments' => 0, 'shares' => 1, 'clicks' => 1, 'reach' => 2],
            'score' => 13,
            'best' => null,
        ]);
    });

    it('names the best post once enough posts are old enough to compete', function () {
        config(['noongun.insights.min_measured_posts' => 2]);

        $best = ($this->measured)(['reactions' => 8, 'shares' => 1], 3, null, null, 'The best of the week');
        ($this->measured)(['reactions' => 2, 'clicks' => 9000], 4);
        snapshotFor(publishedVariant($this->workspace, $this->page, now()->subHours(3), body: 'Too young'), ['reactions' => 700]);

        $insights = $this->actingAs($this->owner)->get(route('dashboard'))->inertiaProps('insights');

        expect($insights['best'])->toMatchArray(['post_id' => $best->post_id, 'excerpt' => 'The best of the week', 'score' => 11]);
    });

    it('is not there for someone without a workspace', function () {
        expect($this->actingAs(User::factory()->create())->get(route('dashboard'))->assertOk()->inertiaProps('insights'))->toBeNull();
    });
});
