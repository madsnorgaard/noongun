<?php

use App\Domain\Channels\Contracts\Metric;
use App\Domain\Channels\Contracts\MetricReading;
use App\Domain\Content\PostState;
use App\Domain\Insights\ChannelPolling;
use App\Domain\Insights\Models\MetricSnapshot;
use App\Domain\Workspaces\Models\Workspace;
use App\Domain\Workspaces\Role;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/../../Support/publishing.php';
require_once __DIR__.'/../../Support/posts.php';
require_once __DIR__.'/../../Support/insights.php';

/**
 * The numbers of a post on the post page and in the history of the queue:
 * every state a reader has to be able to tell apart.
 */
beforeEach(function () {
    $this->travelTo('2026-11-26 10:00:00');
    Queue::fake();

    $this->fake = fakeMetricsAdapter();
    fakeChannelAdapter('linkedin');

    $this->workspace = Workspace::factory()->create(['timezone' => 'Africa/Johannesburg']);
    $this->owner = userWithRole($this->workspace, Role::Owner);
    $this->page = channelIn($this->workspace, ['display_name' => 'Signal Hill']);

    // The numbers of one post as the post page gets them.
    $this->numbersOf = fn (int $postId): ?array => $this->actingAs($this->owner)
        ->get(route('posts.show', $postId))
        ->assertOk()
        ->inertiaProps('insights');

    $this->asked = fn ($variant, DateTimeInterface $at) => DB::table('post_variants')
        ->where('id', $variant->id)
        ->update(['metrics_checked_at' => $at]);
});

describe('a post that is not measured yet', function () {
    it('says when the first reading is expected: by the next run after it went out', function () {
        $variant = publishedVariant($this->workspace, $this->page, now()->subMinutes(4));

        $numbers = ($this->numbersOf)($variant->post_id);

        expect($numbers['total'])->toBeNull()
            ->and($numbers['variants'])->toHaveCount(1)
            ->and($numbers['variants'][0])->toMatchArray([
                'variant_id' => $variant->id,
                'channel_name' => 'Signal Hill',
                'platform_label' => 'Facebook',
                'state' => 'pending',
                'numbers' => null,
                'score' => null,
                'checked_at' => null,
                'next_reading_at' => '2026-11-26T10:06:00+00:00',
                'asked' => false,
                'stopped' => false,
                'stale' => false,
            ]);
    });

    it('expects the next reading one step of the cadence after the platform was last asked', function (string $published, string $asked, string $expected) {
        $variant = publishedVariant($this->workspace, $this->page, now()->parse($published));
        ($this->asked)($variant, now()->parse($asked));

        expect(($this->numbersOf)($variant->post_id)['variants'][0])->toMatchArray([
            'state' => 'pending',
            'asked' => true,
            'next_reading_at' => $expected,
        ]);
    })->with([
        'hourly on the first day' => ['2026-11-26 07:00:00', '2026-11-26 09:30:00', '2026-11-26T10:30:00+00:00'],
        'daily after that' => ['2026-11-23 07:00:00', '2026-11-26 08:00:00', '2026-11-27T08:00:00+00:00'],
    ]);

    it('follows the cadence of the configuration', function () {
        config(['noongun.insights.cadence' => '6:15,48:120']);

        $variant = publishedVariant($this->workspace, $this->page, now()->subHours(2));
        ($this->asked)($variant, now()->subMinutes(5));

        expect(($this->numbersOf)($variant->post_id)['variants'][0]['next_reading_at'])->toBe('2026-11-26T10:10:00+00:00')
            ->and($this->get(route('posts.show', $variant->post_id))->inertiaProps('reading'))->toMatchArray([
                'enabled' => true,
                'cadence' => 'A post is read every 15 minutes until it is 6 hours old, then every 2 hours until it is 2 days old, and not after that.',
                'stop_after' => '2 days',
            ]);
    });

    it('describes the default cadence in plain words', function () {
        $variant = publishedVariant($this->workspace, $this->page);

        expect($this->actingAs($this->owner)->get(route('posts.show', $variant->post_id))->inertiaProps('reading'))->toMatchArray([
            'enabled' => true,
            'cadence' => 'A post is read every hour until it is 1 day old, then once a day until it is 21 days old, and not after that.',
            'stop_after' => '21 days',
            'once_within' => '365 days',
            'can_set_up' => true,
        ]);

        expect($this->actingAs(userWithRole($this->workspace, Role::Editor))->get(route('posts.show', $variant->post_id))->inertiaProps('reading.can_set_up'))->toBeFalse();
    });

    it('says that reading is paused for the channel instead of promising a time', function () {
        $waiting = publishedVariant($this->workspace, $this->page, now()->subMinutes(30));
        $measured = publishedVariant($this->workspace, $this->page, now()->subHours(3));
        $old = publishedVariant($this->workspace, $this->page, now()->subDays(30));
        snapshotFor($measured, ['reactions' => 2]);
        snapshotFor($old, ['reactions' => 2], now()->subDays(20));

        expect(($this->numbersOf)($waiting->post_id)['variants'][0]['pause'])->toBeNull();

        ChannelPolling::pause($this->page->id, 600);

        $other = ['kind' => 'other', 'until' => '2026-11-26T10:10:00+00:00'];

        expect(($this->numbersOf)($waiting->post_id)['variants'][0])->toMatchArray(['state' => 'pending', 'pause' => $other])
            ->and(($this->numbersOf)($measured->post_id)['variants'][0]['pause'])->toBe($other)
            // No reading is expected for a post that is not read any more.
            ->and(($this->numbersOf)($old->post_id)['variants'][0]['pause'])->toBeNull();
    });

    it('says which kind of pause it is and until when, so that the next step is the right one', function () {
        $waiting = publishedVariant($this->workspace, $this->page, now()->subMinutes(30));
        $pause = fn () => ($this->numbersOf)($waiting->post_id)['variants'][0]['pause'];

        ChannelPolling::pause($this->page->id, 1800, ChannelPolling::PAUSE_RATE_LIMIT);

        expect($pause())->toBe(['kind' => 'rate_limit', 'until' => '2026-11-26T10:30:00+00:00']);

        ChannelPolling::resume($this->page->id);
        ChannelPolling::pauseUntilConnectedAgain($this->page->id, 21600);

        expect($pause())->toBe(['kind' => 'token', 'until' => '2026-11-26T16:00:00+00:00']);

        ChannelPolling::connectedAgain($this->page->id);

        expect($pause())->toBeNull();

        // A pause that cannot be read is still a pause, and no more is claimed of it.
        Cache::put("noongun:insights:pause:{$this->page->id}", 'not a time', 600);

        expect($pause())->toBe(['kind' => 'unknown', 'until' => null]);
    });
});

describe('a post that is measured', function () {
    it('shows zeros as zeros', function () {
        $variant = publishedVariant($this->workspace, $this->page, now()->subHours(3));
        snapshotFor($variant, ['reactions' => 0, 'comments' => 0, 'shares' => 0, 'clicks' => 0, 'reach' => 0], now()->subMinutes(20));

        $numbers = ($this->numbersOf)($variant->post_id);

        expect($numbers['variants'][0])->toMatchArray([
            'state' => 'measured',
            'score' => 0,
            'checked_at' => '2026-11-26T09:40:00+00:00',
            'next_reading_at' => null,
            'needs_permission' => [],
        ])
            ->and($numbers['variants'][0]['numbers'])->toBe(array_fill_keys(
                ['reactions', 'comments', 'shares', 'clicks', 'reach'],
                ['value' => 0, 'kept_since' => null, 'missing' => null],
            ))
            ->and($numbers['total'])->toBe([
                'numbers' => ['reactions' => 0, 'comments' => 0, 'shares' => 0, 'clicks' => 0, 'reach' => 0],
                'kept' => [],
                'score' => 0,
                'measured' => 1,
                'published' => 1,
                'checked_at' => '2026-11-26T09:40:00+00:00',
            ]);
    });

    it('shows a number the platform does not have as missing, never as zero', function () {
        $this->fake->givesMetrics([Metric::Reactions, Metric::Comments, Metric::Shares]);

        $variant = publishedVariant($this->workspace, $this->page, now()->subHours(3));
        snapshotFor($variant, ['reactions' => 12, 'comments' => 3, 'shares' => 1]);

        $numbers = ($this->numbersOf)($variant->post_id);

        expect($numbers['variants'][0]['numbers'])->toBe([
            'reactions' => ['value' => 12, 'kept_since' => null, 'missing' => null],
            'comments' => ['value' => 3, 'kept_since' => null, 'missing' => null],
            'shares' => ['value' => 1, 'kept_since' => null, 'missing' => null],
            'clicks' => ['value' => null, 'kept_since' => null, 'missing' => 'platform'],
            'reach' => ['value' => null, 'kept_since' => null, 'missing' => 'platform'],
        ])
            ->and($numbers['variants'][0]['needs_permission'])->toBe([])
            ->and($numbers['total']['numbers'])->toBe(['reactions' => 12, 'comments' => 3, 'shares' => 1, 'clicks' => null, 'reach' => null])
            ->and($numbers['total']['score'])->toBe(21);
    });

    it('names the numbers that need one more permission for this channel', function () {
        $this->fake->channelsMayRead([Metric::Reactions, Metric::Comments, Metric::Shares]);

        $variant = publishedVariant($this->workspace, $this->page, now()->subHours(3));
        snapshotFor($variant, ['reactions' => 12, 'comments' => 3]);

        $measured = ($this->numbersOf)($variant->post_id)['variants'][0];

        expect($measured['needs_permission'])->toBe(['clicks', 'reach'])
            ->and($measured['numbers']['clicks'])->toBe(['value' => null, 'kept_since' => null, 'missing' => 'permission'])
            ->and($measured['numbers']['reach'])->toBe(['value' => null, 'kept_since' => null, 'missing' => 'permission'])
            // Permitted, and the platform still gave nothing this time.
            ->and($measured['numbers']['shares'])->toBe(['value' => null, 'kept_since' => null, 'missing' => 'not_given']);

        $waiting = publishedVariant($this->workspace, $this->page, now()->subMinutes(2));

        expect(($this->numbersOf)($waiting->post_id)['variants'][0])->toMatchArray([
            'state' => 'pending',
            'needs_permission' => ['clicks', 'reach'],
        ]);
    });

    it('says of a number the platform stopped giving that it is kept, and since when', function () {
        $second = channelIn($this->workspace, ['display_name' => 'Lion Battery']);
        $variant = publishedVariant($this->workspace, $this->page, now()->subHours(6));
        $fresh = alsoPublishedOn($this->workspace, $variant, $second);
        $all = Metric::cases();

        $record = fn ($of, array $numbers, $at) => inWorkspace($this->workspace, fn () => MetricSnapshot::record($of, new MetricReading(...$numbers), $at, $all));

        $record($variant, ['reactions' => 4, 'clicks' => 30, 'reach' => 500], now()->subHours(5));
        $record($variant, ['reactions' => 6], now()->subHours(3));
        $record($variant, ['reactions' => 6], now()->subHour());
        $record($fresh, ['reactions' => 1, 'clicks' => 2], now()->subHour());

        $numbers = ($this->numbersOf)($variant->post_id);
        $kept = $numbers['variants'][0];

        expect($kept['numbers']['clicks'])->toBe(['value' => 30, 'kept_since' => '2026-11-26T07:00:00+00:00', 'missing' => null])
            ->and($kept['numbers']['reach'])->toBe(['value' => 500, 'kept_since' => '2026-11-26T07:00:00+00:00', 'missing' => null])
            ->and($kept['numbers']['reactions'])->toBe(['value' => 6, 'kept_since' => null, 'missing' => null])
            ->and($kept['checked_at'])->toBe('2026-11-26T09:00:00+00:00')
            // The row where they were given, then the row that keeps them.
            ->and(array_column($kept['history'], 'kept'))->toBe([[], ['clicks', 'reach']])
            ->and($numbers['variants'][1]['numbers']['clicks'])->toBe(['value' => 2, 'kept_since' => null, 'missing' => null])
            // The total holds a kept number of clicks; reach is not added up at all.
            ->and($numbers['total']['numbers'])->toMatchArray(['clicks' => 32, 'reach' => null])
            ->and($numbers['total']['kept'])->toBe(['clicks']);

        // The list of the queue is told the same, in the same one query.
        $history = $this->get(route('queue.index', ['view' => 'history']))->inertiaProps('history');

        expect($history['insights'][$variant->post_id]['total']['kept'])->toBe(['clicks']);
    });

    it('says that measuring stopped once the post is older than the cadence reaches', function () {
        $old = publishedVariant($this->workspace, $this->page, now()->subDays(30));
        snapshotFor($old, ['reactions' => 40], now()->subDays(9));

        $justInside = publishedVariant($this->workspace, $this->page, now()->subDays(21)->addMinute());
        snapshotFor($justInside, ['reactions' => 1], now()->subDay());

        expect(($this->numbersOf)($old->post_id)['variants'][0])->toMatchArray([
            'state' => 'measured',
            'stopped' => true,
            'stale' => false,
            'checked_at' => '2026-11-17T10:00:00+00:00',
        ])
            ->and(($this->numbersOf)($justInside->post_id)['variants'][0]['stopped'])->toBeFalse();
    });

    it('says that the numbers are stale while the channel has to be connected again', function (string $status) {
        $channel = channelIn($this->workspace, ['status' => $status]);
        $variant = publishedVariant($this->workspace, $channel, now()->subDays(2));
        snapshotFor($variant, ['reactions' => 7], now()->subDay());

        expect(($this->numbersOf)($variant->post_id)['variants'][0])->toMatchArray([
            'state' => 'measured',
            'channel_status' => $status,
            'stale' => true,
            'stopped' => false,
            'checked_at' => '2026-11-25T10:00:00+00:00',
        ]);
    })->with(['needs_reconnect', 'disconnected']);

    it('gives the readings over time, oldest first, one row for each change', function () {
        $variant = publishedVariant($this->workspace, $this->page, now()->subHours(5));

        snapshotFor($variant, ['reactions' => 1], now()->subHours(3));
        snapshotFor($variant, ['reactions' => 1], now()->subHours(2));
        snapshotFor($variant, ['reactions' => 4, 'comments' => 1], now()->subHour());

        expect(($this->numbersOf)($variant->post_id)['variants'][0]['history'])->toBe([
            ['captured_at' => '2026-11-26T07:00:00+00:00', 'checked_at' => '2026-11-26T08:00:00+00:00', 'reactions' => 1, 'comments' => null, 'shares' => null, 'clicks' => null, 'reach' => null, 'kept' => []],
            ['captured_at' => '2026-11-26T09:00:00+00:00', 'checked_at' => '2026-11-26T09:00:00+00:00', 'reactions' => 4, 'comments' => 1, 'shares' => null, 'clicks' => null, 'reach' => null, 'kept' => []],
        ]);
    });
});

describe('a post that will not be measured', function () {
    it('says that LinkedIn gives no numbers, without an error and without zeros', function () {
        $profile = channelIn($this->workspace, ['platform' => 'linkedin', 'display_name' => 'Thandi']);
        $variant = publishedVariant($this->workspace, $profile, now()->subHours(3));

        $numbers = ($this->numbersOf)($variant->post_id);

        expect($numbers['total'])->toBeNull()
            ->and($numbers['variants'][0])->toMatchArray([
                'platform_label' => 'LinkedIn',
                'state' => 'unavailable',
                'numbers' => null,
                'score' => null,
                'next_reading_at' => null,
                'needs_permission' => [],
                'history' => [],
            ]);
    });

    it('tells apart an old post that waits for its one reading, one that gave nothing and one that is too old', function () {
        $waiting = publishedVariant($this->workspace, $this->page, now()->subDays(21));
        $asked = publishedVariant($this->workspace, $this->page, now()->subDays(40));
        $ancient = publishedVariant($this->workspace, $this->page, now()->subDays(366));
        ($this->asked)($asked, now()->subDays(30));

        expect(($this->numbersOf)($waiting->post_id)['variants'][0])->toMatchArray([
            'state' => 'waiting_once',
            'stopped' => true,
            'asked' => false,
            'next_reading_at' => null,
        ])
            ->and(($this->numbersOf)($asked->post_id)['variants'][0])->toMatchArray(['state' => 'no_numbers', 'asked' => true])
            ->and(($this->numbersOf)($ancient->post_id)['variants'][0])->toMatchArray(['state' => 'too_old', 'asked' => false]);

        // With the one reading of old posts turned off, nothing waits for it.
        config(['noongun.insights.backfill_days' => 0]);

        expect(($this->numbersOf)($waiting->post_id)['variants'][0]['state'])->toBe('too_old')
            ->and($this->get(route('posts.show', $waiting->post_id))->inertiaProps('reading.once_within'))->toBeNull();

        config(['noongun.insights.backfill_days' => 30]);

        expect(($this->numbersOf)($waiting->post_id)['variants'][0]['state'])->toBe('waiting_once')
            ->and(($this->numbersOf)($ancient->post_id)['variants'][0]['state'])->toBe('too_old')
            ->and($this->get(route('posts.show', $waiting->post_id))->inertiaProps('reading.once_within'))->toBe('30 days');

        // An old post is not said to be waiting for a reading that cannot come.
        config(['noongun.insights.enabled' => false]);

        expect(($this->numbersOf)($waiting->post_id)['variants'][0]['state'])->toBe('off')
            ->and(($this->numbersOf)($ancient->post_id)['variants'][0]['state'])->toBe('too_old');
    });

    it('does not count clicks in the score, so that a channel with more numbers does not score higher', function () {
        $withClicks = publishedVariant($this->workspace, $this->page, now()->subHours(3));
        $without = publishedVariant($this->workspace, $this->page, now()->subHours(3));
        snapshotFor($withClicks, ['reactions' => 5, 'comments' => 1, 'clicks' => 400, 'reach' => 900]);
        snapshotFor($without, ['reactions' => 5, 'comments' => 1]);

        expect(($this->numbersOf)($withClicks->post_id)['variants'][0]['score'])->toBe(7)
            ->and(($this->numbersOf)($without->post_id)['variants'][0]['score'])->toBe(7)
            ->and(($this->numbersOf)($withClicks->post_id)['total'])->toMatchArray(['score' => 7])
            // One channel: its reach is the reach of the post.
            ->and(($this->numbersOf)($withClicks->post_id)['total']['numbers'])->toMatchArray(['clicks' => 400, 'reach' => 900]);
    });

    it('says that the channel has to be connected again before a first reading', function () {
        $channel = channelIn($this->workspace, ['status' => 'needs_reconnect']);
        $variant = publishedVariant($this->workspace, $channel, now()->subHour());

        expect(($this->numbersOf)($variant->post_id)['variants'][0])->toMatchArray([
            'state' => 'blocked',
            'channel_status' => 'needs_reconnect',
            'next_reading_at' => null,
            'stale' => false,
        ]);
    });

    it('says that the platform reports the post as gone once it is not asked about any more', function () {
        $almost = publishedVariant($this->workspace, $this->page, now()->subHours(5));
        $gone = publishedVariant($this->workspace, $this->page, now()->subHours(5));
        $goneWithNumbers = publishedVariant($this->workspace, $this->page, now()->subHours(5));
        $goneAndOld = publishedVariant($this->workspace, $this->page, now()->subDays(30));
        snapshotFor($goneWithNumbers, ['reactions' => 4], now()->subHours(4));

        DB::table('post_variants')->where('id', $almost->id)->update(['metrics_gone_count' => 2, 'metrics_checked_at' => now()->subMinutes(30)]);
        DB::table('post_variants')->whereIn('id', [$gone->id, $goneWithNumbers->id, $goneAndOld->id])->update(['metrics_gone_count' => 3, 'metrics_checked_at' => now()->subMinutes(30)]);

        expect(($this->numbersOf)($almost->post_id)['variants'][0])->toMatchArray(['state' => 'pending', 'gone' => false])
            ->and(($this->numbersOf)($gone->post_id)['variants'][0])->toMatchArray(['state' => 'gone', 'gone' => true, 'next_reading_at' => null])
            ->and(($this->numbersOf)($goneWithNumbers->post_id)['variants'][0])->toMatchArray(['state' => 'measured', 'gone' => true, 'stale' => false, 'score' => 4])
            // Too old to be read anyway: that is what the page says.
            ->and(($this->numbersOf)($goneAndOld->post_id)['variants'][0])->toMatchArray(['state' => 'no_numbers', 'gone' => false]);

        config(['noongun.insights.gone_after' => 2]);

        expect(($this->numbersOf)($almost->post_id)['variants'][0]['state'])->toBe('gone');
    });

    it('says that insights are turned off on this instance, and still shows what was read before', function () {
        config(['noongun.insights.enabled' => false]);

        $unmeasured = publishedVariant($this->workspace, $this->page, now()->subHour());
        $measured = publishedVariant($this->workspace, $this->page, now()->subHour());
        snapshotFor($measured, ['reactions' => 3]);

        expect(($this->numbersOf)($unmeasured->post_id)['variants'][0])->toMatchArray(['state' => 'off', 'next_reading_at' => null])
            ->and(($this->numbersOf)($measured->post_id)['variants'][0])->toMatchArray(['state' => 'measured', 'score' => 3])
            ->and($this->get(route('posts.show', $measured->post_id))->inertiaProps('reading.enabled'))->toBeFalse();
    });
});

describe('a post on several channels', function () {
    it('shows each channel and the total of those that are measured', function () {
        $second = channelIn($this->workspace, ['display_name' => 'Lion Battery']);
        $profile = channelIn($this->workspace, ['platform' => 'linkedin', 'display_name' => 'Thandi']);

        $onPage = publishedVariant($this->workspace, $this->page, now()->subHours(5));
        $onSecond = alsoPublishedOn($this->workspace, $onPage, $second);
        alsoPublishedOn($this->workspace, $onPage, $profile);

        snapshotFor($onPage, ['reactions' => 5, 'comments' => 1, 'reach' => 300], now()->subHours(2));
        snapshotFor($onSecond, ['reactions' => 2, 'shares' => 1, 'reach' => 80], now()->subHour());

        $numbers = ($this->numbersOf)($onPage->post_id);

        expect(array_column($numbers['variants'], 'state', 'channel_name'))->toBe([
            'Signal Hill' => 'measured',
            'Lion Battery' => 'measured',
            'Thandi' => 'unavailable',
        ])
            ->and(array_column($numbers['variants'], 'score'))->toBe([7, 5, null])
            ->and($numbers['total'])->toBe([
                // Reach is not added up: 300 and 80 may be the same people.
                'numbers' => ['reactions' => 7, 'comments' => 1, 'shares' => 1, 'clicks' => null, 'reach' => null],
                'kept' => [],
                'score' => 12,
                'measured' => 2,
                'published' => 3,
                // As fresh as the oldest of its readings.
                'checked_at' => '2026-11-26T08:00:00+00:00',
            ]);
    });

    it('shows only the texts that are on a platform', function () {
        $second = channelIn($this->workspace);
        $post = postInState($this->workspace, PostState::Failed, [$this->page, $second]);
        [$published, $failed] = variantsOf($post);

        DB::table('post_variants')->where('id', $published->id)->update([
            'external_id' => 'ext_1', 'published_at' => now()->subHour(), 'error' => null,
        ]);
        DB::table('post_variants')->where('id', $failed->id)->update(['external_id' => null, 'published_at' => null]);

        expect(array_column(($this->numbersOf)($post->id)['variants'], 'variant_id'))->toBe([$published->id]);
    });
});

describe('who sees the numbers of which post', function () {
    it('has no numbers for a post that was never published', function (PostState $state) {
        $post = postInState($this->workspace, $state, [$this->page]);

        expect(($this->numbersOf)($post->id))->toBeNull();
    })->with([PostState::Draft, PostState::Scheduled, PostState::Rejected]);

    it('shows them to a viewer', function () {
        $variant = publishedVariant($this->workspace, $this->page, now()->subHour());
        snapshotFor($variant, ['reactions' => 3]);

        $this->actingAs(userWithRole($this->workspace, Role::Viewer))
            ->get(route('posts.show', $variant->post_id))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('insights.variants.0.numbers.reactions.value', 3)
                ->where('post.can.update', false));
    });

    it('answers 404 for a post of another workspace, and never mixes in its numbers', function () {
        $other = Workspace::factory()->create();
        $foreign = publishedVariant($other, null, now()->subHour());
        snapshotFor($foreign, ['reactions' => 99]);

        $own = publishedVariant($this->workspace, $this->page, now()->subHour());

        $this->actingAs($this->owner)->get(route('posts.show', $foreign->post_id))->assertNotFound();

        expect(($this->numbersOf)($own->post_id)['variants'])->toHaveCount(1)
            ->and(($this->numbersOf)($own->post_id)['variants'][0]['state'])->toBe('pending');
    });
});

describe('the history of the queue', function () {
    it('gives the numbers of the published posts on the page, keyed by post', function () {
        $measured = publishedVariant($this->workspace, $this->page, now()->subHours(3));
        $waiting = publishedVariant($this->workspace, $this->page, now()->subMinutes(3));
        $rejected = postInState($this->workspace, PostState::Rejected, [$this->page]);
        snapshotFor($measured, ['reactions' => 8, 'comments' => 2]);

        $other = Workspace::factory()->create();
        $foreign = publishedVariant($other, null, now()->subHour());
        snapshotFor($foreign, ['reactions' => 99]);

        $history = $this->actingAs(userWithRole($this->workspace, Role::Viewer))
            ->get(route('queue.index', ['view' => 'history']))
            ->assertOk()
            ->inertiaProps('history');

        expect(array_keys($history['insights']))->toEqualCanonicalizing([$measured->post_id, $waiting->post_id])
            ->and($history['insights'])->not->toHaveKey($rejected->id)
            ->and($history['insights'][$measured->post_id]['total']['numbers']['reactions'])->toBe(8)
            ->and($history['insights'][$measured->post_id]['total']['score'])->toBe(12)
            // The list does not carry the readings over time.
            ->and($history['insights'][$measured->post_id]['variants'][0]['history'])->toBeNull()
            ->and($history['insights'][$waiting->post_id]['total'])->toBeNull()
            ->and($history['insights'][$waiting->post_id]['variants'][0]['state'])->toBe('pending')
            ->and($history['reading']['enabled'])->toBeTrue();
    });

    it('reads the numbers of a full page in the same number of queries as those of one post', function () {
        $queries = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();

            $this->actingAs($this->owner)->get(route('queue.index', ['view' => 'history']))->assertOk();
            $count = count(DB::getQueryLog());

            DB::disableQueryLog();

            return $count;
        };

        snapshotFor(publishedVariant($this->workspace, $this->page, now()->subHour()), ['reactions' => 1]);
        $withOne = $queries();

        foreach (range(1, 30) as $index) {
            snapshotFor(publishedVariant($this->workspace, $this->page, now()->subHours($index)), ['reactions' => $index]);
        }

        expect($queries())->toBe($withOne);
    });

    it('has no numbers on the working list', function () {
        $this->actingAs($this->owner)->get(route('queue.index'))
            ->assertInertia(fn ($page) => $page->where('history', null));
    });
});
