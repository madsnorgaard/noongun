<?php

namespace App\Domain\Insights;

use App\Domain\Channels\Contracts\Metric;
use App\Domain\Channels\Contracts\MetricReading;
use App\Domain\Content\Models\PostVariant;
use App\Domain\Insights\Models\MetricSnapshot;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\JoinClause;

/**
 * How published posts did, read from metric_snapshots. Read-only, in the
 * current workspace: every query goes through the workspace scope, and
 * every join names the workspace again.
 *
 * Each method is one query, whatever the number of ids, so a page can call
 * it once for everything it lists. "Current numbers" are the newest row of
 * a variant. A variant, post or pillar without numbers is left out or
 * reported as not measured; it is never given zeros.
 */
final class Performance
{
    /**
     * The numbers every channel that is measured gives. The comparable
     * score is made of these and nothing else.
     */
    public const COMPARABLE = [Metric::Reactions, Metric::Comments, Metric::Shares];

    /**
     * comparableScore() over the columns of metric_snapshots, for ranking
     * in the database. It takes the three weights as bindings, see
     * comparableBindings().
     */
    private const COMPARABLE_SQL = '(case when coalesce(metric_snapshots.reactions, metric_snapshots.comments, metric_snapshots.shares) is null then null'
        .' else round(coalesce(metric_snapshots.reactions, 0) * ?::numeric + coalesce(metric_snapshots.comments, 0) * ?::numeric'
        .' + coalesce(metric_snapshots.shares, 0) * ?::numeric, 4) end)';

    private const SUMS = 'sum(metric_snapshots.reactions) as reactions, sum(metric_snapshots.comments) as comments, '
        .'sum(metric_snapshots.shares) as shares, sum(metric_snapshots.clicks) as clicks, sum(metric_snapshots.reach) as reach';

    /**
     * The current numbers of each variant that has any.
     *
     * @param  list<int>  $variantIds
     * @return array<int, MetricSnapshot> keyed by variant id
     */
    public function latest(array $variantIds): array
    {
        if ($variantIds === []) {
            return [];
        }

        return MetricSnapshot::query()
            ->current()
            ->whereIn('post_variant_id', $variantIds)
            ->get()
            ->keyBy('post_variant_id')
            ->all();
    }

    /**
     * Every row of one variant, oldest first: its numbers over time. Each
     * row held from captured_at until at least checked_at.
     *
     * @return Collection<int, MetricSnapshot>
     */
    public function history(int $variantId): Collection
    {
        return MetricSnapshot::query()
            ->where('post_variant_id', $variantId)
            ->orderBy('captured_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * The current numbers of each post, added up over its variants. A post
     * none of whose variants has numbers is not in the answer.
     *
     * @param  list<int>  $postIds
     * @return array<int, PostPerformance> keyed by post id
     */
    public function forPosts(array $postIds): array
    {
        if ($postIds === []) {
            return [];
        }

        $rows = MetricSnapshot::query()
            ->current()
            ->join('post_variants', function (JoinClause $join): void {
                $join->on('post_variants.id', '=', 'metric_snapshots.post_variant_id')
                    ->on('post_variants.workspace_id', '=', 'metric_snapshots.workspace_id');
            })
            ->whereIn('post_variants.post_id', $postIds)
            ->groupBy('post_variants.post_id')
            ->selectRaw('post_variants.post_id as post_id, count(*) as measured_variants, min(metric_snapshots.checked_at) as checked_at, '.self::SUMS)
            ->toBase()
            ->get();

        $posts = [];

        foreach ($rows as $row) {
            $totals = MetricTotals::fromRow($row);

            $posts[(int) $row->post_id] = new PostPerformance(
                postId: (int) $row->post_id,
                totals: $totals,
                measuredVariants: (int) $row->measured_variants,
                score: $totals->score(),
                checkedAt: is_string($row->checked_at) ? CarbonImmutable::parse($row->checked_at, 'UTC') : null,
            );
        }

        return $posts;
    }

    /**
     * One entry for each pillar that has a post with a variant published
     * from $from up to, not including, $until, with the current numbers of
     * those variants. Posts without a pillar are one entry with a null
     * pillar. Ordered by pillar name, the entry without a pillar last.
     *
     * @return list<PillarPerformance>
     */
    public function byPillar(DateTimeInterface $from, DateTimeInterface $until): array
    {
        $rows = PostVariant::query()
            ->join('posts', function (JoinClause $join): void {
                $join->on('posts.id', '=', 'post_variants.post_id')
                    ->on('posts.workspace_id', '=', 'post_variants.workspace_id');
            })
            ->leftJoin('pillars', function (JoinClause $join): void {
                $join->on('pillars.id', '=', 'posts.pillar_id')
                    ->on('pillars.workspace_id', '=', 'posts.workspace_id');
            })
            ->leftJoin('metric_snapshots', function (JoinClause $join): void {
                $join->on('metric_snapshots.post_variant_id', '=', 'post_variants.id')
                    ->on('metric_snapshots.workspace_id', '=', 'post_variants.workspace_id')
                    ->where('metric_snapshots.is_latest', true);
            })
            ->whereNotNull('post_variants.external_id')
            ->where('post_variants.published_at', '>=', CarbonImmutable::instance($from)->utc())
            ->where('post_variants.published_at', '<', CarbonImmutable::instance($until)->utc())
            ->groupBy('posts.pillar_id', 'pillars.name')
            ->orderByRaw('pillars.name asc nulls last')
            ->selectRaw(
                'posts.pillar_id as pillar_id, pillars.name as pillar_name, count(distinct post_variants.post_id) as posts, '
                .'count(distinct post_variants.post_id) filter (where metric_snapshots.id is not null) as measured_posts, '.self::SUMS,
            )
            ->toBase()
            ->get();

        $pillars = [];

        foreach ($rows as $row) {
            $totals = MetricTotals::fromRow($row);

            $pillars[] = new PillarPerformance(
                pillarId: is_numeric($row->pillar_id) ? (int) $row->pillar_id : null,
                pillarName: is_string($row->pillar_name) ? $row->pillar_name : null,
                posts: (int) $row->posts,
                measuredPosts: (int) $row->measured_posts,
                totals: $totals,
                score: $totals->score(),
            );
        }

        return $pillars;
    }

    /**
     * The engagement score of each variant that has numbers to score.
     *
     * @param  list<int>  $variantIds
     * @return array<int, float> keyed by variant id
     */
    public function scores(array $variantIds): array
    {
        $scores = [];

        foreach ($this->latest($variantIds) as $variantId => $snapshot) {
            $score = $snapshot->score();

            if ($score !== null) {
                $scores[$variantId] = $score;
            }
        }

        return $scores;
    }

    /**
     * The variants of the given channels that were published in the last
     * $days days and did best: highest engagement score first, then the
     * newest. Only variants with a score are listed, a score of 0 included.
     * For all channels of a platform, pass the ids of those channels.
     *
     * It ranks what is public and says nothing about approval: a caller
     * that needs approved texts filters for that itself.
     *
     * @param  list<int>  $channelIds
     * @param  list<int>  $exceptVariantIds
     * @param  list<int>  $exceptPostIds
     * @return list<VariantScore>
     */
    public function top(array $channelIds, int $days, int $limit, array $exceptVariantIds = [], array $exceptPostIds = []): array
    {
        if ($channelIds === [] || $days < 1 || $limit < 1) {
            return [];
        }

        $weights = EngagementScore::bindings();

        $rows = MetricSnapshot::query()
            ->current()
            ->join('post_variants', function (JoinClause $join): void {
                $join->on('post_variants.id', '=', 'metric_snapshots.post_variant_id')
                    ->on('post_variants.workspace_id', '=', 'metric_snapshots.workspace_id');
            })
            ->whereIn('post_variants.channel_id', $channelIds)
            ->whereNotIn('post_variants.id', $exceptVariantIds)
            ->whereNotIn('post_variants.post_id', $exceptPostIds)
            ->where('post_variants.published_at', '>=', now()->subDays($days))
            ->whereRaw(EngagementScore::SQL.' is not null', $weights)
            ->orderByRaw(EngagementScore::SQL.' desc', $weights)
            ->orderByDesc('post_variants.published_at')
            ->orderByDesc('post_variants.id')
            ->limit($limit)
            ->selectRaw(
                'post_variants.id as variant_id, post_variants.post_id as post_id, post_variants.channel_id as channel_id, '.EngagementScore::SQL.' as score',
                $weights,
            )
            ->toBase()
            ->get();

        $top = [];

        foreach ($rows as $row) {
            $top[] = new VariantScore((int) $row->variant_id, (int) $row->post_id, (int) $row->channel_id, (float) $row->score);
        }

        return $top;
    }

    /**
     * Every row of each variant, oldest first: histories() is history()
     * for several variants in one query. A variant without rows is not in
     * the answer.
     *
     * @param  list<int>  $variantIds
     * @return array<int, list<MetricSnapshot>> keyed by variant id
     */
    public function histories(array $variantIds): array
    {
        if ($variantIds === []) {
            return [];
        }

        $histories = [];

        $rows = MetricSnapshot::query()
            ->whereIn('post_variant_id', $variantIds)
            ->orderBy('captured_at')
            ->orderBy('id')
            ->get();

        foreach ($rows as $row) {
            $histories[$row->post_variant_id][] = $row;
        }

        return $histories;
    }

    /**
     * The score that pages rank by: reactions, comments and shares times
     * their weights from the configuration, added up. Clicks and reach are
     * left out, because a channel only has them with one more permission:
     * counting them would let a channel with that permission outrank one
     * without it for the same post. Null when none of the three was given.
     *
     * It is additive: the score of a post is the sum over its variants.
     */
    public static function comparableScore(MetricReading|MetricTotals $numbers): ?float
    {
        $score = null;

        foreach (self::COMPARABLE as $metric) {
            $value = $numbers->get($metric);

            if ($value !== null) {
                $score = ($score ?? 0.0) + $value * InsightsConfig::weight($metric);
            }
        }

        return $score === null ? null : round($score, 4);
    }

    /**
     * The weights of the comparable score, keyed by metric name, in the
     * order of the formula.
     *
     * @return array<string, float>
     */
    public static function comparableWeights(): array
    {
        $weights = [];

        foreach (self::COMPARABLE as $metric) {
            $weights[$metric->value] = InsightsConfig::weight($metric);
        }

        return $weights;
    }

    /**
     * One entry for each pillar that has a post with a variant published
     * from $from up to, not including, $until, with the current numbers of
     * those variants. Posts without a pillar are one entry without an id.
     * Ordered by pillar name, the entry without a pillar last.
     *
     * The unit is a channel post: one variant on one channel. With
     * $channelId only the variants on that channel count, which is the
     * only way to compare pillars fairly when channels differ in size.
     * With $settledBefore only variants published at or before that
     * instant are counted as measured: a post that went out an hour ago is
     * still collecting reactions and would pull an average down.
     *
     * @return list<PerformanceSummary>
     */
    public function pillarSummaries(DateTimeInterface $from, DateTimeInterface $until, ?int $channelId = null, ?DateTimeInterface $settledBefore = null): array
    {
        return $this->summaries($from, $until, byChannel: false, channelId: $channelId, settledBefore: $settledBefore);
    }

    /**
     * One entry for each channel that has a variant published from $from up
     * to, not including, $until, with the current numbers of those
     * variants. The name is not read: the caller has the channels. Ordered
     * by channel id.
     *
     * @return list<PerformanceSummary>
     */
    public function channelSummaries(DateTimeInterface $from, DateTimeInterface $until): array
    {
        return $this->summaries($from, $until, byChannel: true, channelId: null, settledBefore: null);
    }

    /**
     * The posts with a variant published from $from up to, not including,
     * $until that did best, counting those variants: highest comparable
     * score first, then the one published last, then the newest post. Only
     * posts with a score are listed, a score of 0 included. The score of
     * each entry is the comparable score.
     *
     * With $channelId only the variants on that channel count. With
     * $settledBefore only variants published at or before that instant
     * compete.
     *
     * @return list<PostPerformance>
     */
    public function topPosts(DateTimeInterface $from, DateTimeInterface $until, int $limit, ?int $channelId = null, ?DateTimeInterface $settledBefore = null): array
    {
        if ($limit < 1) {
            return [];
        }

        $weights = self::comparableBindings();
        $score = 'sum('.self::COMPARABLE_SQL.')';

        $rows = MetricSnapshot::query()
            ->current()
            ->join('post_variants', function (JoinClause $join): void {
                $join->on('post_variants.id', '=', 'metric_snapshots.post_variant_id')
                    ->on('post_variants.workspace_id', '=', 'metric_snapshots.workspace_id');
            })
            ->whereNotNull('post_variants.external_id')
            ->where('post_variants.published_at', '>=', CarbonImmutable::instance($from)->utc())
            ->where('post_variants.published_at', '<', CarbonImmutable::instance($until)->utc())
            ->when($channelId !== null, fn ($query) => $query->where('post_variants.channel_id', $channelId))
            ->when($settledBefore !== null, fn ($query) => $query->where('post_variants.published_at', '<=', CarbonImmutable::instance($settledBefore)->utc()))
            ->groupBy('post_variants.post_id')
            ->havingRaw($score.' is not null', $weights)
            ->orderByRaw($score.' desc', $weights)
            ->orderByRaw('max(post_variants.published_at) desc')
            ->orderByDesc('post_variants.post_id')
            ->limit($limit)
            ->selectRaw('post_variants.post_id as post_id, count(*) as measured_variants, min(metric_snapshots.checked_at) as checked_at, '.self::SUMS)
            ->toBase()
            ->get();

        $posts = [];

        foreach ($rows as $row) {
            $totals = MetricTotals::fromRow($row);

            $posts[] = new PostPerformance(
                postId: (int) $row->post_id,
                totals: $totals,
                measuredVariants: (int) $row->measured_variants,
                score: self::comparableScore($totals),
                checkedAt: is_string($row->checked_at) ? CarbonImmutable::parse($row->checked_at, 'UTC') : null,
            );
        }

        return $posts;
    }

    /**
     * @return list<float>
     */
    private static function comparableBindings(): array
    {
        return array_values(self::comparableWeights());
    }

    /**
     * @return list<PerformanceSummary>
     */
    private function summaries(DateTimeInterface $from, DateTimeInterface $until, bool $byChannel, ?int $channelId, ?DateTimeInterface $settledBefore): array
    {
        $settled = $settledBefore !== null ? CarbonImmutable::instance($settledBefore)->utc() : null;

        $counts = 'count(distinct post_variants.post_id) as posts, count(*) as channel_posts, count(metric_snapshots.id) as measured, '
            .'count(*) filter (where coalesce(metric_snapshots.reactions, metric_snapshots.comments, metric_snapshots.shares) is not null) as scored';

        foreach (Metric::cases() as $metric) {
            $counts .= ", count(metric_snapshots.{$metric->value}) as with_{$metric->value}";
        }

        $query = PostVariant::query()
            // Numbers are only joined for variants that are old enough to
            // be compared, so every count and sum below leaves the rest out.
            ->leftJoin('metric_snapshots', function (JoinClause $join) use ($settled): void {
                $join->on('metric_snapshots.post_variant_id', '=', 'post_variants.id')
                    ->on('metric_snapshots.workspace_id', '=', 'post_variants.workspace_id')
                    ->where('metric_snapshots.is_latest', true);

                if ($settled !== null) {
                    $join->where('post_variants.published_at', '<=', $settled);
                }
            })
            ->whereNotNull('post_variants.external_id')
            ->where('post_variants.published_at', '>=', CarbonImmutable::instance($from)->utc())
            ->where('post_variants.published_at', '<', CarbonImmutable::instance($until)->utc())
            ->when($channelId !== null, fn ($variants) => $variants->where('post_variants.channel_id', $channelId));

        if ($byChannel) {
            $query->groupBy('post_variants.channel_id')
                ->orderBy('post_variants.channel_id')
                ->selectRaw('post_variants.channel_id as id, null as name, '.$counts.', '.self::SUMS);
        } else {
            $query
                ->join('posts', function (JoinClause $join): void {
                    $join->on('posts.id', '=', 'post_variants.post_id')
                        ->on('posts.workspace_id', '=', 'post_variants.workspace_id');
                })
                ->leftJoin('pillars', function (JoinClause $join): void {
                    $join->on('pillars.id', '=', 'posts.pillar_id')
                        ->on('pillars.workspace_id', '=', 'posts.workspace_id');
                })
                ->groupBy('posts.pillar_id', 'pillars.name')
                ->orderByRaw('pillars.name asc nulls last')
                ->selectRaw('posts.pillar_id as id, pillars.name as name, '.$counts.', '.self::SUMS);
        }

        $summaries = [];

        foreach ($query->toBase()->get() as $row) {
            $totals = MetricTotals::fromRow($row);
            $with = [];

            foreach (Metric::cases() as $metric) {
                $with[$metric->value] = (int) $row->{'with_'.$metric->value};
            }

            $summaries[] = new PerformanceSummary(
                id: is_numeric($row->id) ? (int) $row->id : null,
                name: is_string($row->name) ? $row->name : null,
                posts: (int) $row->posts,
                channelPosts: (int) $row->channel_posts,
                measured: (int) $row->measured,
                scored: (int) $row->scored,
                totals: $totals,
                with: $with,
                score: self::comparableScore($totals),
            );
        }

        return $summaries;
    }
}
