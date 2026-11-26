<?php

use App\Domain\Channels\Contracts\Metric;
use App\Domain\Content\PostState;
use App\Domain\Workspaces\Models\Workspace;
use App\Domain\Workspaces\Role;
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
            ->and($this->get(route('posts.show', $variant->post_id))->inertiaProps('reading'))->toBe([
                'enabled' => true,
                'cadence' => 'A post is read every 15 minutes until it is 6 hours old, then every 2 hours until it is 2 days old, and not after that.',
                'stop_after' => '2 days',
            ]);
    });

    it('describes the default cadence in plain words', function () {
        $variant = publishedVariant($this->workspace, $this->page);

        expect($this->actingAs($this->owner)->get(route('posts.show', $variant->post_id))->inertiaProps('reading'))->toBe([
            'enabled' => true,
            'cadence' => 'A post is read every hour until it is 1 day old, then once a day until it is 21 days old, and not after that.',
            'stop_after' => '21 days',
        ]);
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
                ['value' => 0, 'missing' => null],
            ))
            ->and($numbers['total'])->toBe([
                'numbers' => ['reactions' => 0, 'comments' => 0, 'shares' => 0, 'clicks' => 0, 'reach' => 0],
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
            'reactions' => ['value' => 12, 'missing' => null],
            'comments' => ['value' => 3, 'missing' => null],
            'shares' => ['value' => 1, 'missing' => null],
            'clicks' => ['value' => null, 'missing' => 'platform'],
            'reach' => ['value' => null, 'missing' => 'platform'],
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
            ->and($measured['numbers']['clicks'])->toBe(['value' => null, 'missing' => 'permission'])
            ->and($measured['numbers']['reach'])->toBe(['value' => null, 'missing' => 'permission'])
            // Permitted, and the platform still gave nothing this time.
            ->and($measured['numbers']['shares'])->toBe(['value' => null, 'missing' => 'not_given']);

        $waiting = publishedVariant($this->workspace, $this->page, now()->subMinutes(2));

        expect(($this->numbersOf)($waiting->post_id)['variants'][0])->toMatchArray([
            'state' => 'pending',
            'needs_permission' => ['clicks', 'reach'],
        ]);
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
            ['captured_at' => '2026-11-26T07:00:00+00:00', 'checked_at' => '2026-11-26T08:00:00+00:00', 'reactions' => 1, 'comments' => null, 'shares' => null, 'clicks' => null, 'reach' => null],
            ['captured_at' => '2026-11-26T09:00:00+00:00', 'checked_at' => '2026-11-26T09:00:00+00:00', 'reactions' => 4, 'comments' => 1, 'shares' => null, 'clicks' => null, 'reach' => null],
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

    it('says so when the post grew too old without a reading', function () {
        $variant = publishedVariant($this->workspace, $this->page, now()->subDays(21));

        expect(($this->numbersOf)($variant->post_id)['variants'][0])->toMatchArray([
            'state' => 'never',
            'stopped' => true,
            'next_reading_at' => null,
        ]);
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
        snapshotFor($onSecond, ['reactions' => 2, 'shares' => 1], now()->subHour());

        $numbers = ($this->numbersOf)($onPage->post_id);

        expect(array_column($numbers['variants'], 'state', 'channel_name'))->toBe([
            'Signal Hill' => 'measured',
            'Lion Battery' => 'measured',
            'Thandi' => 'unavailable',
        ])
            ->and(array_column($numbers['variants'], 'score'))->toBe([7, 5, null])
            ->and($numbers['total'])->toBe([
                'numbers' => ['reactions' => 7, 'comments' => 1, 'shares' => 1, 'clicks' => null, 'reach' => 300],
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
