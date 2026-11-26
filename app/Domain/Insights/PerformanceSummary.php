<?php

namespace App\Domain\Insights;

use App\Domain\Channels\Contracts\Metric;

/**
 * What the posts of one pillar, or of one channel, did in a period, with
 * what an average needs to be honest: for each number, how many posts had
 * it. A post that is measured does not have every number; reach and clicks
 * are often missing. An average is therefore taken over the posts that had
 * the number, never over all measured posts.
 */
final readonly class PerformanceSummary
{
    /**
     * @param  int|null  $id  the pillar or the channel; null for posts without a pillar
     * @param  int  $posts  posts with a variant published in the period
     * @param  int  $measuredPosts  those of them that have any number
     * @param  int  $scoredPosts  those of them that have a number the score counts
     * @param  array<string, int>  $postsWith  keyed by metric name: posts that have that number
     * @param  float|null  $score  EngagementScore of the totals
     */
    public function __construct(
        public ?int $id,
        public ?string $name,
        public int $posts,
        public int $measuredPosts,
        public int $scoredPosts,
        public MetricTotals $totals,
        public array $postsWith,
        public ?float $score,
    ) {}

    public function postsWith(Metric $metric): int
    {
        return $this->postsWith[$metric->value] ?? 0;
    }

    /**
     * One metric for an average post among those that have it. Null when
     * none has it.
     */
    public function average(Metric $metric): ?float
    {
        $total = $this->totals->get($metric);
        $posts = $this->postsWith($metric);

        return $total === null || $posts === 0 ? null : round($total / $posts, 2);
    }

    /**
     * The score of an average post among those that have one. Null when
     * none has.
     */
    public function averageScore(): ?float
    {
        return $this->score === null || $this->scoredPosts === 0 ? null : round($this->score / $this->scoredPosts, 2);
    }
}
