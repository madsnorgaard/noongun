<?php

namespace App\Domain\Insights;

/**
 * One published variant and its engagement score, from its current numbers.
 */
final readonly class VariantScore
{
    public function __construct(
        public int $variantId,
        public int $postId,
        public int $channelId,
        public float $score,
    ) {}
}
