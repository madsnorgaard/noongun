<?php

use App\Domain\Channels\Contracts\Metric;
use App\Domain\Channels\Contracts\MetricReading;
use App\Domain\Content\Models\Pillar;
use App\Domain\Insights\EngagementScore;
use App\Domain\Insights\MetricTotals;
use App\Domain\Insights\Performance;
use App\Domain\Insights\PillarPerformance;
use App\Domain\Insights\VariantScore;
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
});

/**
 * Runs a callback in the workspace of the test and returns how many
 * queries it made, with its result.
 *
 * @return array{mixed, int}
 */
function countingQueries(Closure $callback): array
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    $result = inWorkspace(test()->workspace, $callback);
    $queries = count(DB::getQueryLog());

    DB::disableQueryLog();

    return [$result, $queries];
}

describe('the engagement score', function () {
    it('adds up the counts by their weights and leaves reach out', function () {
        expect(EngagementScore::of(new MetricReading(reactions: 10, comments: 4, shares: 2, clicks: 7, reach: 5000)))->toBe(31.0)
            ->and(EngagementScore::of(new MetricReading(reactions: 10, comments: 4, shares: 2, clicks: 7)))->toBe(31.0);
    });

    it('works without the numbers a channel does not give', function () {
        expect(EngagementScore::of(new MetricReading(reactions: 10, comments: 4, shares: 2)))->toBe(24.0)
            ->and(EngagementScore::of(new MetricReading(clicks: 3)))->toBe(3.0);
    });

    it('is zero for zeros and nothing when no count was given', function () {
        expect(EngagementScore::of(new MetricReading(reactions: 0, comments: 0, shares: 0)))->toBe(0.0)
            ->and(EngagementScore::of(new MetricReading))->toBeNull()
            ->and(EngagementScore::of(new MetricReading(reach: 900)))->toBeNull()
            ->and((new MetricTotals)->score())->toBeNull();
    });

    it('takes its weights from the configuration, and ignores weights that make no sense', function () {
        config(['noongun.insights.score' => ['reactions' => '0.5', 'comments' => 0, 'shares' => 'many', 'clicks' => -2]]);

        // 10 x 0.5 + 4 x 0 + 2 x 3 (default) + 7 x 1 (default)
        expect(EngagementScore::of(new MetricReading(reactions: 10, comments: 4, shares: 2, clicks: 7)))->toBe(18.0);
    });

    it('gives the same number in the database as in PHP', function (array $numbers) {
        config(['noongun.insights.score.reactions' => 1.25]);

        $variant = publishedVariant($this->workspace, $this->page);
        $snapshot = snapshotFor($variant, ['reach' => 1, ...$numbers]);

        $inDatabase = DB::table('metric_snapshots')->where('id', $snapshot->id)->selectRaw(EngagementScore::SQL.' as score', EngagementScore::bindings())->value('score');

        expect($inDatabase === null ? null : (float) $inDatabase)->toBe($snapshot->score());
    })->with([
        'all four' => [['reactions' => 7, 'comments' => 3, 'shares' => 1, 'clicks' => 12]],
        'without clicks' => [['reactions' => 7, 'comments' => 3, 'shares' => 0]],
        'zeros' => [['reactions' => 0]],
        'reach only' => [[]],
    ]);
});

describe('the current numbers of variants', function () {
    it('gives the newest row of each variant that has numbers, in one query', function () {
        $first = publishedVariant($this->workspace, $this->page);
        $second = publishedVariant($this->workspace, $this->page);
        $unmeasured = publishedVariant($this->workspace, $this->page);

        snapshotFor($first, ['reactions' => 1], now()->subHours(2));
        snapshotFor($first, ['reactions' => 5], now()->subHour());
        snapshotFor($second, ['reactions' => 0, 'comments' => 0]);

        [$latest, $queries] = countingQueries(fn () => $this->performance->latest([$first->id, $second->id, $unmeasured->id]));

        expect($queries)->toBe(1)
            ->and(array_keys($latest))->toEqualCanonicalizing([$first->id, $second->id])
            ->and($latest[$first->id]->reactions)->toBe(5)
            ->and($latest[$second->id]->reading()->toArray())->toMatchArray(['reactions' => 0, 'comments' => 0, 'shares' => null])
            ->and($this->performance->latest([]))->toBe([]);
    });

    it('gives the numbers of one variant over time', function () {
        $variant = publishedVariant($this->workspace, $this->page);

        snapshotFor($variant, ['reactions' => 1], now()->subHours(3));
        snapshotFor($variant, ['reactions' => 1], now()->subHours(2));
        snapshotFor($variant, ['reactions' => 4], now()->subHour());

        $history = inWorkspace($this->workspace, fn () => $this->performance->history($variant->id));

        expect($history->map(fn ($row) => [$row->reactions, $row->captured_at->format('H:i'), $row->checked_at->format('H:i')])->all())
            ->toBe([[1, '07:00', '08:00'], [4, '09:00', '09:00']]);
    });

    it('scores the variants that have counts', function () {
        $scored = publishedVariant($this->workspace, $this->page);
        $reachOnly = publishedVariant($this->workspace, $this->page);
        $unmeasured = publishedVariant($this->workspace, $this->page);

        snapshotFor($scored, ['reactions' => 3, 'shares' => 1]);
        snapshotFor($reachOnly, ['reach' => 500]);

        expect(inWorkspace($this->workspace, fn () => $this->performance->scores([$scored->id, $reachOnly->id, $unmeasured->id])))
            ->toBe([$scored->id => 6.0]);
    });
});

describe('the totals of posts', function () {
    it('adds up the current numbers of the variants of each post, in one query', function () {
        $profile = channelIn($this->workspace, ['platform' => 'linkedin']);

        $onPage = publishedVariant($this->workspace, $this->page);
        $onSecondPage = alsoPublishedOn($this->workspace, $onPage, channelIn($this->workspace));
        $onProfile = alsoPublishedOn($this->workspace, $onPage, $profile);

        snapshotFor($onPage, ['reactions' => 1], now()->subHours(3));
        snapshotFor($onPage, ['reactions' => 10, 'comments' => 2, 'shares' => 0, 'clicks' => 5, 'reach' => 300], now()->subHours(2));
        snapshotFor($onSecondPage, ['reactions' => 4, 'comments' => 1, 'shares' => 1], now()->subHour());

        $single = publishedVariant($this->workspace, $this->page);
        snapshotFor($single, ['reactions' => 0, 'comments' => 0, 'shares' => 0]);

        $unmeasured = publishedVariant($this->workspace, $this->page);

        [$posts, $queries] = countingQueries(fn () => $this->performance->forPosts([$onPage->post_id, $single->post_id, $unmeasured->post_id]));

        expect($queries)->toBe(1)
            ->and(array_keys($posts))->toEqualCanonicalizing([$onPage->post_id, $single->post_id])
            ->and($posts[$onPage->post_id]->totals->toArray())->toBe(['reactions' => 14, 'comments' => 3, 'shares' => 1, 'clicks' => 5, 'reach' => 300])
            ->and($posts[$onPage->post_id]->measuredVariants)->toBe(2)
            ->and($posts[$onPage->post_id]->score)->toBe(14.0 + 6 + 3 + 5)
            ->and($posts[$onPage->post_id]->checkedAt?->toDateTimeString())->toBe('2026-11-26 08:00:00')
            ->and($posts[$single->post_id]->totals->toArray())->toBe(['reactions' => 0, 'comments' => 0, 'shares' => 0, 'clicks' => null, 'reach' => null])
            ->and($posts[$single->post_id]->score)->toBe(0.0)
            ->and($onProfile->post_id)->toBe($onPage->post_id)
            ->and($this->performance->forPosts([]))->toBe([]);
    });
});

describe('the summary for each pillar', function () {
    it('counts the posts published in the period and averages over those that have numbers', function () {
        $devops = inWorkspace($this->workspace, fn () => Pillar::factory()->create(['name' => 'DevOps']));
        $photos = inWorkspace($this->workspace, fn () => Pillar::factory()->create(['name' => 'Photography']));
        inWorkspace($this->workspace, fn () => Pillar::factory()->create(['name' => 'Unused']));

        $first = publishedVariant($this->workspace, $this->page, now()->subDays(2), $devops);
        $firstElsewhere = alsoPublishedOn($this->workspace, $first, channelIn($this->workspace));
        $second = publishedVariant($this->workspace, $this->page, now()->subDays(3), $devops);
        publishedVariant($this->workspace, $this->page, now()->subDays(4), $devops);
        $tooOld = publishedVariant($this->workspace, $this->page, now()->subDays(40), $devops);
        $photo = publishedVariant($this->workspace, $this->page, now()->subDays(5), $photos);
        $loose = publishedVariant($this->workspace, $this->page, now()->subDays(1));

        snapshotFor($first, ['reactions' => 1], now()->subDay());
        snapshotFor($first, ['reactions' => 10, 'comments' => 2, 'shares' => 1, 'clicks' => 8, 'reach' => 400]);
        snapshotFor($firstElsewhere, ['reactions' => 2, 'comments' => 0, 'shares' => 0]);
        snapshotFor($second, ['reactions' => 4, 'comments' => 0, 'shares' => 0]);
        snapshotFor($tooOld, ['reactions' => 1000]);
        snapshotFor($loose, ['reactions' => 3]);

        [$pillars, $queries] = countingQueries(fn () => $this->performance->byPillar(now()->subDays(30), now()));

        expect($queries)->toBe(1)
            ->and(array_map(fn (PillarPerformance $pillar): ?string => $pillar->pillarName, $pillars))->toBe(['DevOps', 'Photography', null]);

        [$forDevops, $forPhotos, $withoutPillar] = $pillars;

        expect($forDevops->pillarId)->toBe($devops->id)
            ->and($forDevops->posts)->toBe(3)
            ->and($forDevops->measuredPosts)->toBe(2)
            ->and($forDevops->totals->toArray())->toBe(['reactions' => 16, 'comments' => 2, 'shares' => 1, 'clicks' => 8, 'reach' => 400])
            ->and($forDevops->score)->toBe(16.0 + 4 + 3 + 8)
            ->and($forDevops->averageScore())->toBe(15.5)
            ->and($forDevops->average(Metric::Reactions))->toBe(8.0)
            ->and($forDevops->average(Metric::Reach))->toBe(200.0)
            ->and($forPhotos->pillarId)->toBe($photos->id)
            ->and($forPhotos->posts)->toBe(1)
            ->and($forPhotos->measuredPosts)->toBe(0)
            ->and($forPhotos->totals->toArray())->toBe(['reactions' => null, 'comments' => null, 'shares' => null, 'clicks' => null, 'reach' => null])
            ->and($forPhotos->score)->toBeNull()
            ->and($forPhotos->averageScore())->toBeNull()
            ->and($forPhotos->average(Metric::Reactions))->toBeNull()
            ->and($withoutPillar->pillarId)->toBeNull()
            ->and($withoutPillar->posts)->toBe(1)
            ->and($withoutPillar->totals->reactions)->toBe(3)
            ->and($photo->post_id)->not->toBe($loose->post_id);
    });

    it('takes the start of the period in and leaves its end out, in any timezone', function (string $timezone) {
        $atStart = publishedVariant($this->workspace, $this->page, new DateTimeImmutable('2026-11-01 00:00:00', new DateTimeZone($timezone)));
        $atEnd = publishedVariant($this->workspace, $this->page, new DateTimeImmutable('2026-12-01 00:00:00', new DateTimeZone($timezone)));
        snapshotFor($atStart, ['reactions' => 1]);
        snapshotFor($atEnd, ['reactions' => 100]);

        $pillars = inWorkspace($this->workspace, fn () => $this->performance->byPillar(
            new DateTimeImmutable('2026-11-01 00:00:00', new DateTimeZone($timezone)),
            new DateTimeImmutable('2026-12-01 00:00:00', new DateTimeZone($timezone)),
        ));

        expect($pillars)->toHaveCount(1)
            ->and($pillars[0]->posts)->toBe(1)
            ->and($pillars[0]->totals->reactions)->toBe(1);
    })->with(['Europe/Copenhagen', 'Africa/Johannesburg']);
});

describe('the posts that did best', function () {
    it('ranks the variants of the given channels by score, the newest first among equals', function () {
        $otherPage = channelIn($this->workspace);

        $good = publishedVariant($this->workspace, $this->page, now()->subDays(10));
        $best = publishedVariant($this->workspace, $this->page, now()->subDays(20));
        $equalOlder = publishedVariant($this->workspace, $this->page, now()->subDays(9));
        $equalNewer = publishedVariant($this->workspace, $this->page, now()->subDays(2));
        $zero = publishedVariant($this->workspace, $this->page, now()->subDays(3));
        $reachOnly = publishedVariant($this->workspace, $this->page, now()->subDays(3));
        $unmeasured = publishedVariant($this->workspace, $this->page, now()->subDays(3));
        $tooOld = publishedVariant($this->workspace, $this->page, now()->subDays(31));
        $elsewhere = publishedVariant($this->workspace, $otherPage, now()->subDays(3));

        snapshotFor($good, ['reactions' => 500], now()->subDays(5));
        snapshotFor($good, ['reactions' => 20, 'comments' => 5]);
        snapshotFor($best, ['reactions' => 10, 'shares' => 30]);
        snapshotFor($equalOlder, ['reactions' => 6]);
        snapshotFor($equalNewer, ['comments' => 3]);
        snapshotFor($zero, ['reactions' => 0, 'comments' => 0, 'shares' => 0]);
        snapshotFor($reachOnly, ['reach' => 100000]);
        snapshotFor($tooOld, ['reactions' => 9999]);
        snapshotFor($elsewhere, ['reactions' => 9999]);

        [$top, $queries] = countingQueries(fn () => $this->performance->top([$this->page->id], days: 30, limit: 10));

        expect($queries)->toBe(1)
            ->and(array_map(fn (VariantScore $entry): array => [$entry->variantId, $entry->score], $top))->toBe([
                [$best->id, 100.0],
                [$good->id, 30.0],
                [$equalNewer->id, 6.0],
                [$equalOlder->id, 6.0],
                [$zero->id, 0.0],
            ])
            ->and($top[0]->postId)->toBe($best->post_id)
            ->and($top[0]->channelId)->toBe($this->page->id)
            ->and($unmeasured->id)->not->toBeIn(array_map(fn (VariantScore $entry): int => $entry->variantId, $top));

        $acrossPages = inWorkspace($this->workspace, fn () => $this->performance->top([$this->page->id, $otherPage->id], days: 30, limit: 2));

        expect(array_map(fn (VariantScore $entry): int => $entry->variantId, $acrossPages))->toBe([$elsewhere->id, $best->id]);
    });

    it('can leave out variants and posts, and gives nothing for no channels', function () {
        $first = publishedVariant($this->workspace, $this->page);
        $second = publishedVariant($this->workspace, $this->page);
        $third = publishedVariant($this->workspace, $this->page);

        snapshotFor($first, ['reactions' => 30]);
        snapshotFor($second, ['reactions' => 20]);
        snapshotFor($third, ['reactions' => 10]);

        $top = inWorkspace($this->workspace, fn () => $this->performance->top([$this->page->id], 30, 10, exceptVariantIds: [$first->id], exceptPostIds: [$second->post_id]));

        expect(array_map(fn (VariantScore $entry): int => $entry->variantId, $top))->toBe([$third->id])
            ->and($this->performance->top([], 30, 10))->toBe([])
            ->and($this->performance->top([$this->page->id], 0, 10))->toBe([])
            ->and($this->performance->top([$this->page->id], 30, 0))->toBe([]);
    });
});

describe('workspace isolation of the queries', function () {
    it('shows nothing of another workspace, even when its ids are asked for', function () {
        $otherWorkspace = Workspace::factory()->create();
        $otherPage = channelIn($otherWorkspace);
        $pillar = inWorkspace($otherWorkspace, fn () => Pillar::factory()->create());
        $foreign = publishedVariant($otherWorkspace, $otherPage, now()->subDay(), $pillar);
        snapshotFor($foreign, ['reactions' => 50]);

        $seen = inWorkspace($this->workspace, fn () => [
            $this->performance->latest([$foreign->id]),
            $this->performance->history($foreign->id)->all(),
            $this->performance->scores([$foreign->id]),
            $this->performance->forPosts([$foreign->post_id]),
            $this->performance->byPillar(now()->subDays(30), now()->addDay()),
            $this->performance->top([$otherPage->id], 30, 10),
        ]);

        expect($seen)->toBe([[], [], [], [], [], []]);

        $own = inWorkspace($otherWorkspace, fn () => $this->performance->top([$otherPage->id], 30, 10));

        expect($own)->toHaveCount(1);
    });

    it('shows nothing without a current workspace', function () {
        $variant = publishedVariant($this->workspace, $this->page, now()->subDay());
        snapshotFor($variant, ['reactions' => 50]);

        expect($this->performance->latest([$variant->id]))->toBe([])
            ->and($this->performance->forPosts([$variant->post_id]))->toBe([])
            ->and($this->performance->byPillar(now()->subDays(30), now()->addDay()))->toBe([])
            ->and($this->performance->top([$this->page->id], 30, 10))->toBe([]);
    });
});
