<?php

use App\Domain\Channels\Contracts\Metric;
use App\Domain\Channels\Contracts\MetricReading;
use App\Domain\Insights\EngagementScore;
use App\Domain\Insights\Performance;
use App\Domain\Insights\PerformanceSummary;
use App\Domain\Insights\PostPerformance;
use App\Domain\Workspaces\Models\Workspace;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../../Support/publishing.php';
require_once __DIR__.'/../../Support/posts.php';
require_once __DIR__.'/../../Support/insights.php';

beforeEach(function () {
    $this->travelTo('2026-11-26 10:00:00');

    $this->workspace = Workspace::factory()->create();
    $this->page = channelIn($this->workspace);
    $this->performance = app(Performance::class);
    $this->from = now()->subDays(7);
    $this->until = now()->addMinute();

    // Runs a callback in the workspace and gives its result with the
    // number of queries it made.
    $this->counted = function (Closure $callback): array {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $result = inWorkspace($this->workspace, $callback);
        $queries = count(DB::getQueryLog());

        DB::disableQueryLog();

        return [$result, $queries];
    };
});

describe('the weights a page prints', function () {
    it('are those of the comparable score, in the order of the formula, without clicks', function () {
        config(['noongun.insights.score' => ['reactions' => '0.5', 'comments' => 0, 'shares' => 4, 'clicks' => 100]]);

        expect(Performance::comparableWeights())->toBe(['reactions' => 0.5, 'comments' => 0.0, 'shares' => 4.0])
            ->and(EngagementScore::comparable(new MetricReading(reactions: 10, comments: 4, shares: 2, clicks: 7)))->toBe(13.0);
    });
});

describe('the readings of several variants over time', function () {
    it('gives the rows of each variant oldest first, in one query', function () {
        $first = publishedVariant($this->workspace, $this->page);
        $second = publishedVariant($this->workspace, $this->page);
        $unmeasured = publishedVariant($this->workspace, $this->page);

        snapshotFor($first, ['reactions' => 1], now()->subHours(3));
        snapshotFor($first, ['reactions' => 1], now()->subHours(2));
        snapshotFor($first, ['reactions' => 4], now()->subHour());
        snapshotFor($second, ['reactions' => 0]);

        [$histories, $queries] = ($this->counted)(fn () => $this->performance->histories([$first->id, $second->id, $unmeasured->id]));

        expect($queries)->toBe(1)
            ->and(array_keys($histories))->toEqualCanonicalizing([$first->id, $second->id])
            ->and(array_map(fn ($row) => [$row->reactions, $row->captured_at->format('H:i'), $row->checked_at->format('H:i')], $histories[$first->id]))
            ->toBe([[1, '07:00', '08:00'], [4, '09:00', '09:00']])
            ->and($this->performance->histories([]))->toBe([]);
    });

    it('never gives the rows of another workspace', function () {
        $other = Workspace::factory()->create();
        $foreign = publishedVariant($other);
        snapshotFor($foreign, ['reactions' => 9]);

        expect(inWorkspace($this->workspace, fn () => $this->performance->histories([$foreign->id])))->toBe([]);
    });
});

describe('the summary of each pillar', function () {
    it('counts for each number the channel posts that have it, so that an average is not taken over posts without it', function () {
        $pillar = pillarIn($this->workspace, ['name' => 'Harbour']);

        $withReach = publishedVariant($this->workspace, $this->page, now()->subDay(), $pillar);
        $withoutReach = publishedVariant($this->workspace, $this->page, now()->subDay(), $pillar);
        $reachOnly = publishedVariant($this->workspace, $this->page, now()->subDay(), $pillar);
        publishedVariant($this->workspace, $this->page, now()->subDay(), $pillar);

        snapshotFor($withReach, ['reactions' => 10, 'comments' => 2, 'shares' => 1, 'clicks' => 50, 'reach' => 900]);
        snapshotFor($withoutReach, ['reactions' => 20, 'comments' => 0, 'shares' => 0]);
        snapshotFor($reachOnly, ['reach' => 100]);

        [$summaries, $queries] = ($this->counted)(fn () => $this->performance->pillarSummaries($this->from, $this->until));

        expect($queries)->toBe(1)->and($summaries)->toHaveCount(1);

        /** @var PerformanceSummary $harbour */
        $harbour = $summaries[0];

        expect([$harbour->id, $harbour->name])->toBe([$pillar->id, 'Harbour'])
            ->and([$harbour->posts, $harbour->channelPosts, $harbour->measured, $harbour->scored])->toBe([4, 4, 3, 2])
            ->and($harbour->with)->toBe(['reactions' => 2, 'comments' => 2, 'shares' => 2, 'clicks' => 1, 'reach' => 2])
            ->and($harbour->average(Metric::Reactions))->toBe(15.0)
            // 1000 over the two that have reach, not over the three measured.
            ->and($harbour->average(Metric::Reach))->toBe(500.0)
            ->and($harbour->average(Metric::Clicks))->toBe(50.0)
            // (10 + 2 x 2 + 1 x 3) + 20 = 37: the clicks are not in it.
            ->and($harbour->score)->toBe(37.0)
            ->and($harbour->averageScore())->toBe(18.5);
    });

    it('takes one post on one channel as the unit, so that going to three channels does not triple a pillar', function () {
        $wide = pillarIn($this->workspace, ['name' => 'On three channels']);
        $narrow = pillarIn($this->workspace, ['name' => 'On one channel']);
        $second = channelIn($this->workspace);
        $third = channelIn($this->workspace);

        $onPage = publishedVariant($this->workspace, $this->page, now()->subDay(), $wide);
        $onSecond = alsoPublishedOn($this->workspace, $onPage, $second);
        $onThird = alsoPublishedOn($this->workspace, $onPage, $third);
        $single = publishedVariant($this->workspace, $this->page, now()->subDay(), $narrow);
        $loose = publishedVariant($this->workspace, $this->page, now()->subDay());

        foreach ([$onPage, $onSecond, $onThird, $single] as $variant) {
            snapshotFor($variant, ['reactions' => 10]);
        }

        snapshotFor($loose, ['reactions' => 1]);

        $rows = fn (?int $channelId = null) => array_map(
            fn (PerformanceSummary $summary) => [$summary->name, $summary->posts, $summary->channelPosts, $summary->measured, $summary->totals->reactions, $summary->averageScore()],
            inWorkspace($this->workspace, fn () => $this->performance->pillarSummaries($this->from, $this->until, $channelId)),
        );

        // The same post did the same on every channel: the same average.
        expect($rows())->toBe([
            ['On one channel', 1, 1, 1, 10, 10.0],
            ['On three channels', 1, 3, 3, 30, 10.0],
            [null, 1, 1, 1, 1, 1.0],
        ])
            // One channel alone: only what went out there.
            ->and($rows($second->id))->toBe([['On three channels', 1, 1, 1, 10, 10.0]])
            ->and($rows(999999))->toBe([]);
    });

    it('counts a post that is too young as published and not as measured', function () {
        $pillar = pillarIn($this->workspace, ['name' => 'Harbour']);

        snapshotFor(publishedVariant($this->workspace, $this->page, now()->subDays(3), $pillar), ['reactions' => 20]);
        snapshotFor(publishedVariant($this->workspace, $this->page, now()->subHours(48), $pillar), ['reactions' => 10]);
        snapshotFor(publishedVariant($this->workspace, $this->page, now()->subHours(2), $pillar), ['reactions' => 0]);

        $harbour = inWorkspace($this->workspace, fn () => $this->performance->pillarSummaries($this->from, $this->until, null, now()->subHours(48)))[0];

        expect([$harbour->posts, $harbour->channelPosts, $harbour->measured, $harbour->scored])->toBe([3, 3, 2, 2])
            ->and($harbour->totals->reactions)->toBe(30)
            ->and($harbour->averageScore())->toBe(15.0);
    });

    it('leaves out what was published outside the period and what belongs to another workspace', function () {
        $pillar = pillarIn($this->workspace, ['name' => 'Harbour']);
        snapshotFor(publishedVariant($this->workspace, $this->page, now()->subDays(8), $pillar), ['reactions' => 5]);
        snapshotFor(publishedVariant($this->workspace, $this->page, $this->until, $pillar), ['reactions' => 5]);

        $other = Workspace::factory()->create();
        snapshotFor(publishedVariant($other, null, now()->subDay(), pillarIn($other, ['name' => 'Foreign'])), ['reactions' => 99]);

        expect(inWorkspace($this->workspace, fn () => $this->performance->pillarSummaries($this->from, $this->until)))->toBe([]);
    });
});

describe('the summary of each channel', function () {
    it('adds up the posts of each channel, measured or not, in one query', function () {
        $profile = channelIn($this->workspace, ['platform' => 'linkedin']);

        $onPage = publishedVariant($this->workspace, $this->page, now()->subDay());
        alsoPublishedOn($this->workspace, $onPage, $profile);
        $second = publishedVariant($this->workspace, $this->page, now()->subDays(2));
        publishedVariant($this->workspace, $this->page, now()->subDays(3));

        snapshotFor($onPage, ['reactions' => 3, 'clicks' => 7]);
        snapshotFor($second, ['reactions' => 0]);

        $other = Workspace::factory()->create();
        snapshotFor(publishedVariant($other, null, now()->subDay()), ['reactions' => 99]);

        [$summaries, $queries] = ($this->counted)(fn () => $this->performance->channelSummaries($this->from, $this->until));

        expect($queries)->toBe(1)
            ->and(array_map(fn (PerformanceSummary $summary) => [$summary->id, $summary->channelPosts, $summary->measured, $summary->totals->reactions, $summary->with(Metric::Clicks), $summary->score], $summaries))
            ->toBe([
                // The seven clicks are a number of the channel, not part of its score.
                [$this->page->id, 3, 2, 3, 1, 3.0],
                [$profile->id, 1, 0, null, 0, null],
            ]);
    });
});

describe('the posts that did best', function () {
    it('ranks posts by the comparable score of all their channels, the newest first among equals', function () {
        $second = channelIn($this->workspace);

        $best = publishedVariant($this->workspace, $this->page, now()->subDays(3));
        $bestElsewhere = alsoPublishedOn($this->workspace, $best, $second);
        $tiedOlder = publishedVariant($this->workspace, $this->page, now()->subDays(2));
        $tiedNewer = publishedVariant($this->workspace, $this->page, now()->subDay());
        $sameMinuteLower = publishedVariant($this->workspace, $this->page, now()->subDays(4));
        $sameMinuteHigher = publishedVariant($this->workspace, $this->page, now()->subDays(4));
        $zero = publishedVariant($this->workspace, $this->page, now()->subDay());
        $clicksOnly = publishedVariant($this->workspace, $this->page, now()->subDay());
        publishedVariant($this->workspace, $this->page, now()->subDay());

        snapshotFor($best, ['reactions' => 6]);
        snapshotFor($bestElsewhere, ['reactions' => 6, 'reach' => 40]);
        snapshotFor($tiedOlder, ['reactions' => 10]);
        snapshotFor($tiedNewer, ['comments' => 5]);
        // Many clicks do not lift a post over the others.
        snapshotFor($sameMinuteLower, ['reactions' => 2, 'clicks' => 5000]);
        snapshotFor($sameMinuteHigher, ['reactions' => 2]);
        snapshotFor($zero, ['reactions' => 0]);
        snapshotFor($clicksOnly, ['clicks' => 900, 'reach' => 5000]);

        [$top, $queries] = ($this->counted)(fn () => $this->performance->topPosts($this->from, $this->until, 10));

        expect($queries)->toBe(1)
            ->and(array_map(fn (PostPerformance $post) => [$post->postId, $post->score], $top))->toBe([
                [$best->post_id, 12.0],
                [$tiedNewer->post_id, 10.0],
                [$tiedOlder->post_id, 10.0],
                [$sameMinuteHigher->post_id, 2.0],
                [$sameMinuteLower->post_id, 2.0],
                [$zero->post_id, 0.0],
            ])
            ->and([$top[0]->measuredVariants, $top[0]->totals->reactions])->toBe([2, 12]);

        // On one channel alone, the post that was on two counts once.
        $onSecond = inWorkspace($this->workspace, fn () => $this->performance->topPosts($this->from, $this->until, 10, $second->id));

        expect(array_map(fn (PostPerformance $post) => [$post->postId, $post->score, $post->measuredVariants], $onSecond))
            ->toBe([[$best->post_id, 6.0, 1]]);
    });

    it('leaves out posts that are too young to compete', function () {
        $settled = publishedVariant($this->workspace, $this->page, now()->subDays(3));
        $atTheLine = publishedVariant($this->workspace, $this->page, now()->subHours(48));
        $young = publishedVariant($this->workspace, $this->page, now()->subHours(47));

        snapshotFor($settled, ['reactions' => 1]);
        snapshotFor($atTheLine, ['reactions' => 2]);
        snapshotFor($young, ['reactions' => 300]);

        $ids = fn (?DateTimeInterface $settledBefore) => array_map(
            fn (PostPerformance $post) => $post->postId,
            inWorkspace($this->workspace, fn () => $this->performance->topPosts($this->from, $this->until, 10, null, $settledBefore)),
        );

        expect($ids(now()->subHours(48)))->toBe([$atTheLine->post_id, $settled->post_id])
            ->and($ids(null))->toBe([$young->post_id, $atTheLine->post_id, $settled->post_id]);
    });

    it('stops at the limit and keeps to the period and the workspace', function () {
        $inside = publishedVariant($this->workspace, $this->page, now()->subDay());
        $alsoInside = publishedVariant($this->workspace, $this->page, now()->subDays(2));
        $before = publishedVariant($this->workspace, $this->page, now()->subDays(8));

        snapshotFor($inside, ['reactions' => 1]);
        snapshotFor($alsoInside, ['reactions' => 2]);
        snapshotFor($before, ['reactions' => 50]);

        $other = Workspace::factory()->create();
        snapshotFor(publishedVariant($other, null, now()->subDay()), ['reactions' => 99]);

        $ids = fn (int $limit) => array_map(
            fn (PostPerformance $post) => $post->postId,
            inWorkspace($this->workspace, fn () => $this->performance->topPosts($this->from, $this->until, $limit)),
        );

        expect($ids(10))->toBe([$alsoInside->post_id, $inside->post_id])
            ->and($ids(1))->toBe([$alsoInside->post_id])
            ->and($ids(0))->toBe([]);
    });
});
