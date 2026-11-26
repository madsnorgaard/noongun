<?php

namespace App\Domain\Insights;

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
}
