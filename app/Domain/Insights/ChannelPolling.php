<?php

namespace App\Domain\Insights;

use App\Domain\Channels\Contracts\RateLimit;
use App\Domain\Scheduling\Exceptions\LockStoreNotShared;
use App\Domain\Scheduling\VariantLock;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

/**
 * What keeps reading metrics from getting in the way, for one channel. All
 * of it lives in the cache store, which must be shared by every worker, as
 * for publishing.
 *
 * - The lock: one run reads a channel at a time.
 * - The pause: after the platform refused a read, the channel is left alone
 *   until the time the platform asked for, or the configured pause.
 * - The budget: reading may use a share of the channel's rate limit
 *   (noongun.insights.rate_limit_percent), counted here. The rest is never
 *   touched by reading, so publishing has calls left whatever is polled.
 *   Publishing is not counted and not held back by any of this.
 */
final class ChannelPolling
{
    /**
     * @throws LockStoreNotShared
     */
    public static function lock(int $channelId): Lock
    {
        VariantLock::assertStoreIsShared();

        return Cache::lock("noongun:insights:channel:{$channelId}", InsightsConfig::lockSeconds());
    }

    public static function isPaused(int $channelId): bool
    {
        return Cache::has(self::pauseKey($channelId));
    }

    /**
     * Leaves the channel alone for the given seconds, at least one and at
     * most InsightsConfig::LONGEST_PAUSE_SECONDS. A pause already in place
     * is replaced.
     */
    public static function pause(int $channelId, int $seconds): void
    {
        $seconds = min(max($seconds, 1), InsightsConfig::LONGEST_PAUSE_SECONDS);

        Cache::put(self::pauseKey($channelId), now()->addSeconds($seconds)->getTimestamp(), $seconds);
    }

    public static function resume(int $channelId): void
    {
        Cache::forget(self::pauseKey($channelId));
    }

    /**
     * How many calls reading may still make for this channel in the current
     * window of its rate limit. Without a known limit only the size of a
     * run bounds it.
     */
    public static function callsLeft(int $channelId, ?RateLimit $limit): int
    {
        if ($limit === null) {
            return PHP_INT_MAX;
        }

        return RateLimiter::remaining(self::callsKey($channelId), self::allowance($limit));
    }

    /**
     * Counts calls against the share, before they are made: a call that
     * fails was still a call.
     */
    public static function spend(int $channelId, ?RateLimit $limit, int $calls): void
    {
        if ($limit === null || $calls < 1) {
            return;
        }

        RateLimiter::increment(self::callsKey($channelId), $limit->perSeconds, $calls);
    }

    /**
     * The share of a rate limit that reading may use, in calls.
     */
    public static function allowance(RateLimit $limit): int
    {
        return intdiv($limit->maxCalls * InsightsConfig::rateLimitPercent(), 100);
    }

    private static function pauseKey(int $channelId): string
    {
        return "noongun:insights:pause:{$channelId}";
    }

    private static function callsKey(int $channelId): string
    {
        return "noongun:insights:calls:{$channelId}";
    }
}
