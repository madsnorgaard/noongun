<?php

namespace App\Domain\Scheduling;

use App\Domain\Content\Models\PostVariant;
use App\Domain\Content\PostState;
use DateTimeInterface;

/**
 * Which times in a period already hold a post, per channel. Read-only, in
 * the current workspace. A rejected post holds nothing: it was turned down,
 * so its time is free again.
 */
final class PlannedTimes
{
    /**
     * @return array<int, list<int>> channel id => Unix timestamps of the posts planned for it
     */
    public function takenBetween(DateTimeInterface $from, DateTimeInterface $until): array
    {
        $variants = PostVariant::query()
            ->whereHas('post', fn ($posts) => $posts
                ->whereIn('state', self::holdingStates())
                ->where('scheduled_at', '>=', $from)
                ->where('scheduled_at', '<', $until))
            ->with('post')
            ->get();

        $taken = [];

        foreach ($variants as $variant) {
            $at = $variant->post?->scheduled_at;

            if ($at !== null) {
                $taken[$variant->channel_id][] = $at->getTimestamp();
            }
        }

        return $taken;
    }

    /**
     * Every state but rejected.
     *
     * @return list<PostState>
     */
    public static function holdingStates(): array
    {
        $states = [];

        foreach (PostState::cases() as $state) {
            if ($state !== PostState::Rejected) {
                $states[] = $state;
            }
        }

        return $states;
    }
}
