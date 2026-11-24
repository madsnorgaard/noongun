<?php

namespace App\Domain\Channels\Contracts;

use InvalidArgumentException;

/**
 * At most $maxCalls calls in any window of $perSeconds seconds.
 */
final readonly class RateLimit
{
    public function __construct(
        public int $maxCalls,
        public int $perSeconds,
    ) {
        if ($maxCalls < 1 || $perSeconds < 1) {
            throw new InvalidArgumentException('A rate limit needs at least one call and one second.');
        }
    }

    /**
     * @return array{max_calls: int, per_seconds: int}
     */
    public function toArray(): array
    {
        return ['max_calls' => $this->maxCalls, 'per_seconds' => $this->perSeconds];
    }
}
