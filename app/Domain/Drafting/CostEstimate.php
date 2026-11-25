<?php

namespace App\Domain\Drafting;

/**
 * What a run is estimated to have cost. The amount is a decimal string with
 * six places, as stored in draft_runs.cost_estimate. An estimate, from the
 * price table in config, and not what the provider invoices.
 */
final readonly class CostEstimate
{
    public function __construct(
        public string $amount,
        public string $currency,
    ) {}
}
