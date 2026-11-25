<?php

namespace App\Http\Presenters;

/**
 * How an amount of money from draft_runs.cost_estimate (a decimal string
 * with six places) is written on a page. Amounts are counted in whole
 * millionths, so no float holds money.
 */
final class Money
{
    private const MILLIONTHS = 1_000_000;

    /**
     * An estimate before the fact, rounded up to whole cents: "0.65".
     */
    public static function upToCents(string $amount): string
    {
        $cents = intdiv(self::millionths($amount) + 9_999, 10_000);

        return sprintf('%d.%02d', intdiv($cents, 100), $cents % 100);
    }

    /**
     * What was used, to four places, because one draft costs a fraction of
     * a cent: "0.0054".
     */
    public static function used(string $amount): string
    {
        $tenThousandths = intdiv(self::millionths($amount) + 50, 100);

        return sprintf('%d.%04d', intdiv($tenThousandths, 10_000), $tenThousandths % 10_000);
    }

    /**
     * The sum of several amounts, as a decimal string with six places.
     *
     * @param  iterable<string|null>  $amounts
     */
    public static function sum(iterable $amounts): string
    {
        $total = 0;

        foreach ($amounts as $amount) {
            $total += $amount !== null ? self::millionths($amount) : 0;
        }

        return sprintf('%d.%06d', intdiv($total, self::MILLIONTHS), $total % self::MILLIONTHS);
    }

    private static function millionths(string $amount): int
    {
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');

        return max(0, (int) $whole * self::MILLIONTHS + (int) str_pad(substr($fraction, 0, 6), 6, '0'));
    }
}
