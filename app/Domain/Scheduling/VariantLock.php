<?php

namespace App\Domain\Scheduling;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;

/**
 * The lock that must be held while a platform is called for one variant, or
 * while its outcome is settled. Everything that publishes takes it through
 * here, so there is one key per variant in the whole application.
 *
 * The cache store must be shared by every worker (Redis or the database).
 */
final class VariantLock
{
    public static function for(int $variantId, int $seconds): Lock
    {
        return Cache::lock(self::key($variantId), $seconds);
    }

    public static function key(int $variantId): string
    {
        return "noongun:publish:variant:{$variantId}";
    }
}
