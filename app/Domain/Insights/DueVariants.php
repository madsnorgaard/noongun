<?php

namespace App\Domain\Insights;

use App\Domain\Channels\Models\Channel;
use App\Domain\Content\Models\PostVariant;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Which published variants of a channel should have their numbers read now,
 * in the current workspace.
 *
 * A variant is due when it is published, younger than the last step of the
 * cadence (InsightsConfig::cadence()), and was last asked about longer ago
 * than the step for its age allows, or never. The ones that waited longest
 * come first. Whether the channel may be read at all is not decided here.
 *
 * Only post_variants is read: what state the post is in does not matter. A
 * variant that is public has numbers, also when another variant of its post
 * failed.
 */
final class DueVariants
{
    /**
     * @return Collection<int, PostVariant>
     */
    public function for(Channel $channel, int $limit, ?DateTimeInterface $now = null): Collection
    {
        if ($limit < 1) {
            return new Collection;
        }

        return $this->query($channel, $now)
            ->orderByRaw('post_variants.metrics_checked_at asc nulls first')
            ->orderBy('post_variants.id')
            ->limit($limit)
            ->get();
    }

    public function exist(Channel $channel, ?DateTimeInterface $now = null): bool
    {
        return $this->query($channel, $now)->exists();
    }

    /**
     * @return Builder<PostVariant>
     */
    private function query(Channel $channel, ?DateTimeInterface $now): Builder
    {
        $now = CarbonImmutable::instance($now ?? now())->utc();
        $olderThanHours = 0;
        $steps = [];

        foreach (InsightsConfig::cadence() as $step) {
            $steps[] = [
                'published_after' => $now->subHours($step['until_hours']),
                'published_until' => $now->subHours($olderThanHours),
                'checked_before' => $now->subMinutes($step['every_minutes'])->addSeconds(
                    min(InsightsConfig::DUE_TOLERANCE_SECONDS, $step['every_minutes'] * 30),
                ),
            ];
            $olderThanHours = $step['until_hours'];
        }

        return PostVariant::query()
            ->where('post_variants.channel_id', $channel->id)
            ->whereNotNull('post_variants.external_id')
            ->where(function (Builder $due) use ($steps): void {
                foreach ($steps as $step) {
                    $due->orWhere(function (Builder $query) use ($step): void {
                        $query->where('post_variants.published_at', '>', $step['published_after'])
                            ->where('post_variants.published_at', '<=', $step['published_until'])
                            ->where(function (Builder $checked) use ($step): void {
                                $checked->whereNull('post_variants.metrics_checked_at')
                                    ->orWhere('post_variants.metrics_checked_at', '<=', $step['checked_before']);
                            });
                    });
                }
            });
    }
}
