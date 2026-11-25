<?php

namespace App\Domain\Drafting;

/**
 * Estimates what a run cost from its token counts and the price table in
 * config('noongun.drafting.prices'). No price lives in code.
 *
 * The price is looked up for the model that answered and, when that one has
 * no price, for the model that was asked for: providers answer with a dated
 * name for an alias. Without a price there is no estimate, and the run is
 * recorded with its tokens only.
 */
final class CostEstimator
{
    private const PER = 1_000_000;

    private const DECIMALS = 6;

    public function estimate(DraftUsage $usage, ?string $requestedModel = null): ?CostEstimate
    {
        $price = DraftingConfig::price($usage->provider, $usage->model)
            ?? ($requestedModel !== null ? DraftingConfig::price($usage->provider, $requestedModel) : null);

        if ($price === null) {
            return null;
        }

        // Whole millionths of the currency unit, so no float holds money.
        $millionths = intdiv(
            $usage->inputTokens * self::millionths($price['input']) + $usage->outputTokens * self::millionths($price['output']) + intdiv(self::PER, 2),
            self::PER,
        );

        return new CostEstimate(
            sprintf('%d.%0'.self::DECIMALS.'d', intdiv($millionths, 10 ** self::DECIMALS), $millionths % 10 ** self::DECIMALS),
            DraftingConfig::currency(),
        );
    }

    private static function millionths(float $pricePerMillionTokens): int
    {
        return (int) round($pricePerMillionTokens * 10 ** self::DECIMALS);
    }
}
