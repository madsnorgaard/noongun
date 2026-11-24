<?php

namespace App\Domain\Scheduling\Exceptions;

use RuntimeException;

/**
 * The cache store cannot hold a lock that every worker sees, so the lock on
 * a variant would protect nothing. Publishing refuses to run until the store
 * is changed.
 */
class LockStoreNotShared extends RuntimeException
{
    public static function for(string $store, string $driver): self
    {
        return new self(
            "Publishing is stopped: the cache store [{$store}] uses the [{$driver}] driver, which is not shared between workers, "
            .'so the locks that prevent a post from being published twice would not work. Set CACHE_STORE to redis or database.'
        );
    }
}
