<?php

namespace App\Domain\Insights;

use App\Domain\Channels\Contracts\Metric;

/**
 * What the posts of one pillar did in a period. A post counts when one of
 * its variants was published in the period; it is measured when one of
 * those variants has numbers.
 */
final readonly class PillarPerformance
{
    /**
     * @param  int|null  $pillarId  null for posts without a pillar
     * @param  int  $posts  posts with a variant published in the period
     * @param  int  $measuredPosts  those of them that have numbers
     * @param  float|null  $score  EngagementScore of the totals
     */
    public function __construct(
        public ?int $pillarId,
        public ?string $pillarName,
        public int $posts,
        public int $measuredPosts,
        public MetricTotals $totals,
        public ?float $score,
    ) {}

    /**
     * The score of an average measured post. Null when no post is measured.
     */
    public function averageScore(): ?float
    {
        return $this->score === null || $this->measuredPosts === 0 ? null : round($this->score / $this->measuredPosts, 2);
    }

    /**
     * One metric for an average measured post. Null when the metric was not
     * given for any of them.
     */
    public function average(Metric $metric): ?float
    {
        $total = $this->totals->get($metric);

        return $total === null || $this->measuredPosts === 0 ? null : round($total / $this->measuredPosts, 2);
    }
}
