<?php

namespace App\Domain\Content;

use App\Domain\Content\Models\PostVariant;
use App\Domain\Insights\EngagementScore;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\JoinClause;

/**
 * The texts a human signed off: the newest approvals, and those that did
 * better with readers than the channel usually does. Read-only, in the
 * current workspace. This is the one place that decides the order in which
 * approved texts are offered as examples.
 *
 * A text counts while its post carries an approval: approved, scheduled,
 * publishing, published or failed. A failed post and one whose outcome is
 * unknown count, because a person approved their text. A draft, a rejected
 * post and a post that was returned to draft carry no approval and do not
 * count, whatever they were before.
 *
 * The variants are read through the workspace scope, and the join to the
 * post names the workspace again.
 */
final class ApprovedTexts
{
    /**
     * @param  list<int>  $channelIds  the channels the texts were written for
     * @param  list<int>  $exceptVariantIds
     * @param  list<int>  $exceptPostIds
     * @return Collection<int, PostVariant> each with post_approved_at and post_pillar_id of its post
     */
    public function latest(array $channelIds, int $limit, array $exceptVariantIds = [], array $exceptPostIds = []): Collection
    {
        if ($channelIds === [] || $limit < 1) {
            return new Collection;
        }

        return PostVariant::query()
            ->join('posts', function (JoinClause $join): void {
                $join->on('posts.id', '=', 'post_variants.post_id')
                    ->on('posts.workspace_id', '=', 'post_variants.workspace_id');
            })
            ->whereIn('posts.state', self::states())
            ->whereNotNull('posts.approved_at')
            ->whereIn('post_variants.channel_id', $channelIds)
            ->whereNotIn('post_variants.id', $exceptVariantIds)
            ->whereNotIn('post_variants.post_id', $exceptPostIds)
            ->whereRaw("post_variants.body ~ '\\S'")
            ->orderByDesc('posts.approved_at')
            ->orderByDesc('post_variants.id')
            ->limit($limit)
            ->select(['post_variants.*', 'posts.approved_at as post_approved_at', 'posts.pillar_id as post_pillar_id'])
            ->get();
    }

    /**
     * The texts of one channel that did well: published in the last $days
     * days, with a score (Insights\EngagementScore) above the average
     * score of the approved texts of that channel in the same days. Best
     * score first, then the newest approval, then the highest id. Of the
     * posts of one pillar at most $perPillar are given, the best of them;
     * a post without a pillar is not held to that.
     *
     * A text without numbers has no score and is never here: not measured
     * is not the same as a score of 0. It does not count towards the
     * average either. With one measured text, or with the same score for
     * all, nothing is above the average and nothing is given.
     *
     * The numbers are read through the same workspace as the texts: the
     * join names the workspace of the snapshot as well as the variant.
     *
     * @return Collection<int, PostVariant> each with post_approved_at, post_pillar_id and example_score
     */
    public function best(int $channelId, int $days, int $limit, int $perPillar): Collection
    {
        if ($days < 1 || $limit < 1 || $perPillar < 1) {
            return new Collection;
        }

        $score = EngagementScore::SQL;
        $weights = EngagementScore::bindings();

        $measured = PostVariant::query()
            ->join('posts', function (JoinClause $join): void {
                $join->on('posts.id', '=', 'post_variants.post_id')
                    ->on('posts.workspace_id', '=', 'post_variants.workspace_id');
            })
            ->join('metric_snapshots', function (JoinClause $join): void {
                $join->on('metric_snapshots.post_variant_id', '=', 'post_variants.id')
                    ->on('metric_snapshots.workspace_id', '=', 'post_variants.workspace_id')
                    ->where('metric_snapshots.is_latest', true);
            })
            ->whereIn('posts.state', self::states())
            ->whereNotNull('posts.approved_at')
            ->where('post_variants.channel_id', $channelId)
            ->where('post_variants.published_at', '>=', now()->subDays($days))
            ->whereRaw("post_variants.body ~ '\\S'")
            ->whereRaw($score.' is not null', $weights)
            ->select(['post_variants.*', 'posts.approved_at as post_approved_at', 'posts.pillar_id as post_pillar_id'])
            ->selectRaw(
                $score.' as example_score, avg('.$score.') over () as example_average, '
                .'row_number() over (partition by coalesce(posts.pillar_id, -post_variants.id) order by '.$score.' desc, posts.approved_at desc, post_variants.id desc) as pillar_rank',
                [...$weights, ...$weights, ...$weights],
            );

        // Named as the table, so that the workspace scope of the outer
        // query finds its column.
        return PostVariant::query()
            ->fromSub($measured, 'post_variants')
            ->whereColumn('post_variants.example_score', '>', 'post_variants.example_average')
            ->where('post_variants.pillar_rank', '<=', $perPillar)
            ->orderByDesc('post_variants.example_score')
            ->orderByDesc('post_variants.post_approved_at')
            ->orderByDesc('post_variants.id')
            ->limit($limit)
            ->get();
    }

    /**
     * Every state in which a post carries a human approval.
     *
     * @return list<PostState>
     */
    public static function states(): array
    {
        return array_values(array_filter(PostState::cases(), fn (PostState $state): bool => $state->requiresApproval()));
    }
}
