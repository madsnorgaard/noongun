<?php

namespace App\Domain\Insights;

use App\Domain\Channels\Contracts\RateLimit;
use App\Domain\Scheduling\Exceptions\LockStoreNotShared;
use App\Domain\Scheduling\VariantLock;
use Carbon\CarbonImmutable;
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
 *   until the time the platform asked for, or the configured pause. A pause
 *   for a token that was not accepted also ends when the channel is
 *   connected again.
 * - The budget: reading may use a share of the channel's rate limit
 *   (noongun.insights.rate_limit_percent), counted here. The rest is never
 *   touched by reading, so publishing has calls left whatever is polled.
 *   Publishing is not counted and not held back by any of this.
 */
final class ChannelPolling
{
    public const PAUSE_RATE_LIMIT = 'rate_limit';

    public const PAUSE_TOKEN = 'token';

    public const PAUSE_OTHER = 'other';

    private static function time(mixed $timestamp): ?CarbonImmutable
    {
        return is_int($timestamp) && $timestamp > 0 ? CarbonImmutable::createFromTimestampUTC($timestamp) : null;
    }

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
        return Cache::has(self::pauseKey($channelId)) || Cache::has(self::tokenPauseKey($channelId));
    }

    /**
     * Why reading is paused for a channel and until when, or null when it
     * is not paused. For a page that wants to say so.
     *
     * - PAUSE_RATE_LIMIT: the platform is limiting requests. `until` is when
     *   it asked to be left alone until, or the end of the configured pause.
     * - PAUSE_TOKEN: the platform did not accept the token for reading.
     *   Connecting the channel again ends it at once; `until` is when
     *   reading is tried again if nobody does.
     * - PAUSE_OTHER: any other refusal or a temporary problem. `until` is
     *   the end of the configured pause.
     *
     * With more than one in place the order above decides. `until` is null
     * only when the stored time cannot be read.
     *
     * @return array{kind: self::PAUSE_*, until: CarbonImmutable|null}|null
     */
    public static function pauseOf(int $channelId): ?array
    {
        $wait = Cache::get(self::pauseKey($channelId));
        $token = Cache::get(self::tokenPauseKey($channelId));

        // Before the kind was stored the value was the time alone.
        $kind = is_array($wait) && ($wait['kind'] ?? null) === self::PAUSE_RATE_LIMIT ? self::PAUSE_RATE_LIMIT : self::PAUSE_OTHER;
        $until = is_array($wait) ? ($wait['until'] ?? null) : $wait;

        return match (true) {
            $wait !== null && $kind === self::PAUSE_RATE_LIMIT => ['kind' => $kind, 'until' => self::time($until)],
            $token !== null => ['kind' => self::PAUSE_TOKEN, 'until' => self::time($token)],
            $wait !== null => ['kind' => $kind, 'until' => self::time($until)],
            default => null,
        };
    }

    /**
     * Leaves the channel alone for the given seconds, at least one and at
     * most InsightsConfig::LONGEST_PAUSE_SECONDS. A pause already in place
     * is replaced. $kind is PAUSE_RATE_LIMIT when the platform is limiting
     * requests, and PAUSE_OTHER for everything else.
     *
     * @param  self::PAUSE_RATE_LIMIT|self::PAUSE_OTHER  $kind
     */
    public static function pause(int $channelId, int $seconds, string $kind = self::PAUSE_OTHER): void
    {
        $seconds = min(max($seconds, 1), InsightsConfig::LONGEST_PAUSE_SECONDS);

        Cache::put(self::pauseKey($channelId), ['kind' => $kind, 'until' => now()->addSeconds($seconds)->getTimestamp()], $seconds);
    }

    /**
     * Leaves the channel alone because the platform did not accept its
     * token for reading. Kept apart from pause(), because this one ends
     * when the channel is connected again (connectedAgain()), and a wait
     * the platform asked for does not.
     */
    public static function pauseUntilConnectedAgain(int $channelId, int $seconds): void
    {
        $seconds = min(max($seconds, 1), InsightsConfig::LONGEST_PAUSE_SECONDS);

        Cache::put(self::tokenPauseKey($channelId), now()->addSeconds($seconds)->getTimestamp(), $seconds);
    }

    /**
     * The channel has a new token: a pause that was about the old one is
     * over. A pause the platform asked for stays.
     */
    public static function connectedAgain(int $channelId): void
    {
        Cache::forget(self::tokenPauseKey($channelId));
    }

    public static function resume(int $channelId): void
    {
        Cache::forget(self::pauseKey($channelId));
        Cache::forget(self::tokenPauseKey($channelId));
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

    private static function tokenPauseKey(int $channelId): string
    {
        return "noongun:insights:pause-token:{$channelId}";
    }

    private static function callsKey(int $channelId): string
    {
        return "noongun:insights:calls:{$channelId}";
    }
}
