<?php

use App\Domain\Channels\Contracts\Metric;
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
    it('counts for each number the posts that have it, so that an average is not taken over posts without it', function () {
        $pillar = pillarIn($this->workspace, ['name' => 'Harbour']);

        $withReach = publishedVariant($this->workspace, $this->page, now()->subDay(), $pillar);
        $withoutReach = publishedVariant($this->workspace, $this->page, now()->subDay(), $pillar);
        $reachOnly = publishedVariant($this->workspace, $this->page, now()->subDay(), $pillar);
        publishedVariant($this->workspace, $this->page, now()->subDay(), $pillar);

        snapshotFor($withReach, ['reactions' => 10, 'comments' => 2, 'shares' => 1, 'reach' => 900]);
        snapshotFor($withoutReach, ['reactions' => 20, 'comments' => 0, 'shares' => 0]);
        snapshotFor($reachOnly, ['reach' => 100]);

        [$summaries, $queries] = ($this->counted)(fn () => $this->performance->pillarSummaries($this->from, $this->until));

        expect($queries)->toBe(1)->and($summaries)->toHaveCount(1);

        /** @var PerformanceSummary $harbour */
        $harbour = $summaries[0];

        expect([$harbour->id, $harbour->name])->toBe([$pillar->id, 'Harbour'])
            ->and([$harbour->posts, $harbour->measuredPosts, $harbour->scoredPosts])->toBe([4, 3, 2])
            ->and($harbour->totals->toArray())->toBe(['reactions' => 30, 'comments' => 2, 'shares' => 1, 'clicks' => null, 'reach' => 1000])
            ->and($harbour->postsWith)->toBe(['reactions' => 2, 'comments' => 2, 'shares' => 2, 'clicks' => 0, 'reach' => 2])
            ->and($harbour->average(Metric::Reactions))->toBe(15.0)
            // 1000 over the two posts that have reach, not over the three measured.
            ->and($harbour->average(Metric::Reach))->toBe(500.0)
            ->and($harbour->average(Metric::Clicks))->toBeNull()
            // (10 + 2 x 2 + 1 x 3) + 20 = 37, over the two posts that have a score.
            ->and($harbour->score)->toBe(37.0)
            ->and($harbour->averageScore())->toBe(18.5);
    });

    it('counts a post on several channels once and keeps posts without a pillar apart', function () {
        $pillar = pillarIn($this->workspace, ['name' => 'Harbour']);
        $second = channelIn($this->workspace);

        $onPage = publishedVariant($this->workspace, $this->page, now()->subDay(), $pillar);
        $onSecond = alsoPublishedOn($this->workspace, $onPage, $second);
        $loose = publishedVariant($this->workspace, $this->page, now()->subDay());

        snapshotFor($onPage, ['reactions' => 4]);
        snapshotFor($onSecond, ['reactions' => 6, 'reach' => 50]);
        snapshotFor($loose, ['reactions' => 1]);

        $summaries = inWorkspace($this->workspace, fn () => $this->performance->pillarSummaries($this->from, $this->until));

        expect(array_map(fn (PerformanceSummary $summary) => [$summary->name, $summary->posts, $summary->measuredPosts, $summary->totals->reactions, $summary->postsWith(Metric::Reach)], $summaries))
            ->toBe([['Harbour', 1, 1, 10, 1], [null, 1, 1, 1, 0]]);
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
            ->and(array_map(fn (PerformanceSummary $summary) => [$summary->id, $summary->posts, $summary->measuredPosts, $summary->totals->reactions, $summary->postsWith(Metric::Clicks), $summary->score], $summaries))
            ->toBe([
                [$this->page->id, 3, 2, 3, 1, 10.0],
                [$profile->id, 1, 0, null, 0, null],
            ]);
    });
});

describe('the posts that did best', function () {
    it('ranks posts by the score of all their channels, the newest first among equals', function () {
        $second = channelIn($this->workspace);

        $best = publishedVariant($this->workspace, $this->page, now()->subDays(3));
        $bestElsewhere = alsoPublishedOn($this->workspace, $best, $second);
        $tiedOlder = publishedVariant($this->workspace, $this->page, now()->subDays(2));
        $tiedNewer = publishedVariant($this->workspace, $this->page, now()->subDay());
        $sameMinuteLower = publishedVariant($this->workspace, $this->page, now()->subDays(4));
        $sameMinuteHigher = publishedVariant($this->workspace, $this->page, now()->subDays(4));
        $zero = publishedVariant($this->workspace, $this->page, now()->subDay());
        $reachOnly = publishedVariant($this->workspace, $this->page, now()->subDay());
        publishedVariant($this->workspace, $this->page, now()->subDay());

        snapshotFor($best, ['reactions' => 6]);
        snapshotFor($bestElsewhere, ['reactions' => 6, 'reach' => 40]);
        snapshotFor($tiedOlder, ['reactions' => 10]);
        snapshotFor($tiedNewer, ['comments' => 5]);
        snapshotFor($sameMinuteLower, ['reactions' => 2]);
        snapshotFor($sameMinuteHigher, ['reactions' => 2]);
        snapshotFor($zero, ['reactions' => 0]);
        snapshotFor($reachOnly, ['reach' => 5000]);

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
            ->and([$top[0]->measuredVariants, $top[0]->totals->reactions, $top[0]->totals->reach])->toBe([2, 12, 40]);
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
