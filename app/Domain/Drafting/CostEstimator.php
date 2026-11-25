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
 *
 * Input tokens a provider read from or wrote to its prompt cache are priced
 * with "cached_input" and "cache_write" of the model when the table has
 * them, and as plain input otherwise.
 */
final class CostEstimator
{
    private const PER = 1_000_000;

    private const DECIMALS = 6;

    public function estimate(DraftUsage $usage, ?string $requestedModel = null): ?CostEstimate
    {
        $price = self::priceFor($usage, $requestedModel);

        if ($price === null) {
            return null;
        }

        $plainInput = $usage->inputTokens - $usage->cacheReadTokens - $usage->cacheWriteTokens;

        return self::amount(
            $plainInput * self::millionths($price['input'])
            + $usage->cacheReadTokens * self::millionths($price['cached_input'])
            + $usage->cacheWriteTokens * self::millionths($price['cache_write'])
            + $usage->outputTokens * self::millionths($price['output']),
        );
    }

    /**
     * The most the usage can cost, for a bound that is worked out before a
     * provider is asked: every input token at the dearest of the input
     * prices, because it is not known which of them a provider will cache.
     */
    public function atMost(DraftUsage $usage, ?string $requestedModel = null): ?CostEstimate
    {
        $price = self::priceFor($usage, $requestedModel);

        if ($price === null) {
            return null;
        }

        return self::amount(
            $usage->inputTokens * self::millionths(max($price['input'], $price['cached_input'], $price['cache_write']))
            + $usage->outputTokens * self::millionths($price['output']),
        );
    }

    /**
     * @return array{input: float, output: float, cached_input: float, cache_write: float}|null
     */
    private static function priceFor(DraftUsage $usage, ?string $requestedModel): ?array
    {
        return DraftingConfig::price($usage->provider, $usage->model)
            ?? ($requestedModel !== null ? DraftingConfig::price($usage->provider, $requestedModel) : null);
    }

    /**
     * @param  int  $tokensTimesMillionths  tokens times the price per million tokens in millionths of the currency unit
     */
    private static function amount(int $tokensTimesMillionths): CostEstimate
    {
        // Whole millionths of the currency unit, so no float holds money.
        $millionths = intdiv($tokensTimesMillionths + intdiv(self::PER, 2), self::PER);

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
