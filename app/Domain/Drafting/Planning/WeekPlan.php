<?php

namespace App\Domain\Drafting\Planning;

use App\Domain\Content\Models\Pillar;
use Carbon\CarbonImmutable;

/**
 * What proposing one week would do, worked out without creating anything:
 * the posts to draft, and why the other slot times are left alone. The
 * rule is in PlanWeek.
 */
final readonly class WeekPlan
{
    /**
     * @param  string  $week  the Monday of the week on the workspace calendar, Y-m-d
     * @param  CarbonImmutable  $from  the first instant of the week, in UTC
     * @param  CarbonImmutable  $until  the first instant after the week, in UTC
     * @param  list<PlannedPost>  $posts  in time order
     * @param  int  $past  slot times that have already passed
     * @param  int  $taken  slot times that already hold a post that is not rejected
     * @param  int  $unavailable  slots of channels that cannot publish now
     * @param  int  $leftOut  open times beyond the most one proposal drafts
     * @param  list<array{pillar: Pillar, missing: int}>  $short  pillars whose weekly target does not fit in the open times
     * @param  int  $soon  slot times that are closer than the lead time
     */
    public function __construct(
        public string $week,
        public CarbonImmutable $from,
        public CarbonImmutable $until,
        public array $posts,
        public int $past = 0,
        public int $taken = 0,
        public int $unavailable = 0,
        public int $leftOut = 0,
        public array $short = [],
        public int $soon = 0,
    ) {}

    public function isEmpty(): bool
    {
        return $this->posts === [];
    }

    /**
     * A fingerprint of what would be drafted. The page that shows the plan
     * sends it back when the person starts the proposal, so that a plan
     * that changed in the meantime is shown again instead of being run
     * unseen.
     */
    public function fingerprint(): string
    {
        $content = array_map(fn (PlannedPost $post): array => [
            $post->at->getTimestamp(),
            $post->channelIds(),
            $post->pillar?->id,
            $post->idea?->id,
        ], $this->posts);

        return substr(hash('sha256', (string) json_encode([$this->week, $content])), 0, 20);
    }
}
