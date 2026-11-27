<?php

namespace App\Domain\Insights;

use Carbon\CarbonImmutable;

/**
 * The current numbers of one post, added up over its variants that have
 * any.
 */
final readonly class PostPerformance
{
    /**
     * @param  int  $measuredVariants  how many variants of the post have numbers
     * @param  float|null  $score  EngagementScore of the totals
     * @param  CarbonImmutable|null  $checkedAt  when the oldest of these numbers was last confirmed by its platform
     */
    public function __construct(
        public int $postId,
        public MetricTotals $totals,
        public int $measuredVariants,
        public ?float $score,
        public ?CarbonImmutable $checkedAt,
    ) {}
}
