<?php

namespace App\Domain\Scheduling;

/**
 * Typed access to config('noongun.publishing'), with values that make no
 * sense (zero attempts, an empty backoff list) replaced by safe ones.
 */
final class PublishingConfig
{
    /** Seconds on top of the platform calls for a job's own work. */
    public const JOB_SLACK_SECONDS = 15;

    public static function queue(): string
    {
        $queue = config('noongun.publishing.queue');

        return is_string($queue) && $queue !== '' ? $queue : 'default';
    }

    /**
     * How many times one variant is sent at most in one round of publishing.
     */
    public static function maxAttempts(): int
    {
        return self::int('max_attempts', 5, 1);
    }

    /**
     * Seconds to wait before retry number $retry, counted from 1. The last
     * configured value repeats.
     */
    public static function backoffBefore(int $retry): int
    {
        $backoff = self::backoff();

        return $backoff[min(max($retry, 1), count($backoff)) - 1];
    }

    /**
     * The longest wait the job ever asks for between two attempts.
     */
    public static function longestBackoff(): int
    {
        return max(self::backoff());
    }

    /**
     * Seconds allowed for one platform call.
     */
    public static function timeout(): int
    {
        return self::int('timeout', 30, 1);
    }

    /**
     * How many variants one run of the job sends before it hands the rest
     * to a successor.
     */
    public static function callsPerJob(): int
    {
        return self::int('calls_per_job', 3, 1);
    }

    /**
     * The time limit of a job that may send $calls variants: the platform
     * calls plus a margin for the job's own work.
     */
    public static function jobSeconds(int $calls): int
    {
        return self::timeout() * min(max($calls, 1), self::callsPerJob()) + self::JOB_SLACK_SECONDS;
    }

    /**
     * The longest time limit any publishing job can have. The retry_after
     * of the queue connection must be above this, or the queue hands a job
     * that is still running to a second worker.
     */
    public static function longestJobSeconds(): int
    {
        return self::jobSeconds(self::callsPerJob());
    }

    /**
     * How long the lock on a variant lives at least. Never shorter than the
     * longest job, so a lock cannot expire while its holder is alive.
     */
    public static function lockSeconds(): int
    {
        return max(self::int('lock_seconds', 120, 1), self::longestJobSeconds() + 30);
    }

    /**
     * A post in the publishing state whose job has given no sign of life
     * for this long is treated as interrupted. The longest backoff is added
     * because a job that waits for its next attempt is silent by design.
     */
    public static function stuckAfterSeconds(): int
    {
        return self::int('stuck_after_minutes', 30, 1) * 60 + self::longestBackoff();
    }

    /**
     * @return non-empty-list<int>
     */
    private static function backoff(): array
    {
        $configured = config('noongun.publishing.backoff');
        $backoff = [];

        foreach (is_array($configured) ? $configured : [] as $seconds) {
            if (is_numeric($seconds) && (int) $seconds > 0) {
                $backoff[] = (int) $seconds;
            }
        }

        return $backoff === [] ? [60] : $backoff;
    }

    private static function int(string $key, int $default, int $min): int
    {
        $value = config("noongun.publishing.{$key}");

        return max($min, is_numeric($value) ? (int) $value : $default);
    }
}
