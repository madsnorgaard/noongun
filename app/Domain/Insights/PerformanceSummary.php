<?php

namespace App\Domain\Insights;

use App\Domain\Channels\Contracts\Metric;

/**
 * What the posts of one pillar, or of one channel, did in a period.
 *
 * The unit is a channel post: one variant, a post on one channel. A post on
 * three channels is three channel posts, so that an average says what one
 * post did on one channel and a pillar does not look three times as good
 * because it went to three channels.
 *
 * A channel post that is measured does not have every number; clicks and
 * reach are often missing. An average is therefore taken over the channel
 * posts that have the number, never over all measured ones.
 */
final readonly class PerformanceSummary
{
    /**
     * @param  int|null  $id  the pillar or the channel; null for posts without a pillar
     * @param  int  $posts  posts with a variant published in the period
     * @param  int  $channelPosts  those variants
     * @param  int  $measured  the variants that have any number and are old enough to be compared
     * @param  int  $scored  those of them that have a number the comparable score counts
     * @param  array<string, int>  $with  keyed by metric name: measured variants that have that number
     * @param  float|null  $score  EngagementScore::comparable() of the totals
     */
    public function __construct(
        public ?int $id,
        public ?string $name,
        public int $posts,
        public int $channelPosts,
        public int $measured,
        public int $scored,
        public MetricTotals $totals,
        public array $with,
        public ?float $score,
    ) {}

    public function with(Metric $metric): int
    {
        return $this->with[$metric->value] ?? 0;
    }

    /**
     * One metric for an average channel post among those that have it.
     * Null when none has it.
     */
    public function average(Metric $metric): ?float
    {
        $total = $this->totals->get($metric);
        $count = $this->with($metric);

        return $total === null || $count === 0 ? null : round($total / $count, 2);
    }

    /**
     * The comparable score of an average channel post among those that
     * have one. Null when none has.
     */
    public function averageScore(): ?float
    {
        return $this->score === null || $this->scored === 0 ? null : round($this->score / $this->scored, 2);
    }
}
