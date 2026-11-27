<?php

namespace App\Domain\Channels;

use App\Domain\Scheduling\PublishingConfig;

/**
 * Typed access to config('noongun.token_check'), with values that make no
 * sense replaced by safe ones. The only class that reads these keys.
 */
final class TokenCheckConfig
{
    /** Seconds on top of the platform call for the job's own work. */
    private const JOB_SLACK_SECONDS = 15;

    /** How long a queued check keeps a second one for the same channel from being queued. */
    public const UNIQUE_SECONDS = 3600;

    public static function enabled(): bool
    {
        return config('noongun.token_check.enabled') !== false;
    }

    /**
     * The queue the checks run on. By default the one for reading metrics,
     * which has a worker of its own, so a check never waits behind or in
     * front of a post.
     */
    public static function queue(): string
    {
        $queue = config('noongun.token_check.queue');

        return is_string($queue) && $queue !== '' ? $queue : 'insights';
    }

    /**
     * Hours between two checks of one channel.
     */
    public static function everyHours(): int
    {
        return min(self::int('every_hours', 24, 1), 720);
    }

    /**
     * The most channels one run of the command queues a check for, over
     * all workspaces.
     */
    public static function channelsPerRun(): int
    {
        return min(self::int('channels_per_run', 50, 1), 1000);
    }

    /**
     * The time limit of one check: one platform call and some slack.
     */
    public static function jobSeconds(): int
    {
        return PublishingConfig::timeout() + self::JOB_SLACK_SECONDS;
    }

    private static function int(string $key, int $default, int $min): int
    {
        $value = config("noongun.token_check.{$key}");

        return max($min, is_numeric($value) ? (int) $value : $default);
    }
}
