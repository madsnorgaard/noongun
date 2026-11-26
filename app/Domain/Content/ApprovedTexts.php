<?php

namespace App\Domain\Content;

use App\Domain\Content\Models\PostVariant;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\JoinClause;

/**
 * The texts a human signed off, newest approval first. Read-only, in the
 * current workspace.
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
     * Every state in which a post carries a human approval.
     *
     * @return list<PostState>
     */
    public static function states(): array
    {
        return array_values(array_filter(PostState::cases(), fn (PostState $state): bool => $state->requiresApproval()));
    }
}
