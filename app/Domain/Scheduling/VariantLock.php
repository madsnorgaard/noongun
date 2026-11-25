<?php

namespace App\Domain\Scheduling;

use App\Domain\Scheduling\Exceptions\LockStoreNotShared;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;

/**
 * The lock that must be held while a platform is called for one variant, or
 * while its outcome is settled. Everything that publishes takes it through
 * here, so there is one key per variant in the whole application.
 *
 * The cache store must be shared by every worker (Redis or the database).
 * With a store that lives in one process or one container the lock would
 * protect nothing, so outside the test environment such a store is refused:
 * nothing is dispatched and nothing is published until it is changed.
 */
final class VariantLock
{
    /** Cache drivers whose locks are not seen by other workers. */
    private const NOT_SHARED = ['array', 'file', 'null'];

    /**
     * @throws LockStoreNotShared
     */
    public static function for(int $variantId, int $seconds): Lock
    {
        self::assertStoreIsShared();

        return Cache::lock(self::key($variantId), $seconds);
    }

    public static function key(int $variantId): string
    {
        return "noongun:publish:variant:{$variantId}";
    }

    /**
     * @throws LockStoreNotShared
     */
    public static function assertStoreIsShared(): void
    {
        if (app()->environment('testing')) {
            return;
        }

        $store = config('cache.default');
        $store = is_string($store) ? $store : 'unknown';
        $driver = config("cache.stores.{$store}.driver");
        $driver = is_string($driver) ? $driver : 'null';

        if (in_array($driver, self::NOT_SHARED, true)) {
            throw LockStoreNotShared::for($store, $driver);
        }
    }
}
