<?php

namespace App\Domain\Insights;

use App\Domain\Channels\Contracts\Metric;

/**
 * Typed access to config('noongun.insights'), with values that make no
 * sense (an empty cadence, a share of zero) replaced by safe ones. The only
 * class that reads these keys.
 */
final class InsightsConfig
{
    /** Read every hour during the first day, then daily until three weeks old. */
    private const DEFAULT_CADENCE = [[24, 60], [504, 1440]];

    /** Seconds on top of the time limit of a job before its channel lock may expire. */
    private const LOCK_SLACK_SECONDS = 30;

    /** A variant counts as due this many seconds early, so a run that starts a little sooner than the last one does not skip it. */
    public const DUE_TOLERANCE_SECONDS = 300;

    /** The longest a channel is ever left alone after a refusal, whatever the platform asks for. */
    public const LONGEST_PAUSE_SECONDS = 86400;

    /** How long a channel is left alone when the platform no longer accepts its token for reading. */
    public const RECONNECT_PAUSE_SECONDS = 21600;

    /**
     * How long a queued job keeps a second one for the same channel from
     * being queued. It ends when the job has run; this is only the limit
     * for a job that was lost, and what bounds the backlog when no worker
     * runs: one job for each channel in this time, not one every run of
     * the scheduler.
     */
    public const UNIQUE_SECONDS = 3600;

    /**
     * How many answers in a row that a post is gone it takes before the
     * post is not asked about any more. More than one, because platforms
     * give the same answer for a post that was deleted and for one they
     * cannot show at the moment.
     */
    public static function goneAfter(): int
    {
        return min(self::int('gone_after', 3, 1), 100);
    }

    public static function enabled(): bool
    {
        return config('noongun.insights.enabled') !== false;
    }

    public static function queue(): string
    {
        $queue = config('noongun.insights.queue');

        return is_string($queue) && $queue !== '' ? $queue : 'insights';
    }

    /**
     * The steps of the cadence, youngest first: until a post is
     * `until_hours` old it is read every `every_minutes`.
     *
     * @return non-empty-list<array{until_hours: int, every_minutes: int}>
     */
    public static function cadence(): array
    {
        $configured = config('noongun.insights.cadence');
        $steps = [];

        foreach (is_string($configured) ? explode(',', $configured) : [] as $pair) {
            $parts = array_map(trim(...), explode(':', $pair));

            if (count($parts) === 2 && ctype_digit($parts[0]) && ctype_digit($parts[1]) && (int) $parts[0] > 0 && (int) $parts[1] > 0) {
                $steps[(int) $parts[0]] = (int) $parts[1];
            }
        }

        if ($steps === []) {
            $steps = array_column(self::DEFAULT_CADENCE, 1, 0);
        }

        ksort($steps);

        $cadence = [];

        foreach ($steps as $untilHours => $everyMinutes) {
            $cadence[] = ['until_hours' => $untilHours, 'every_minutes' => $everyMinutes];
        }

        return $cadence;
    }

    /**
     * The age in hours after which a post is not read any more.
     */
    public static function stopAfterHours(): int
    {
        $cadence = self::cadence();

        return $cadence[count($cadence) - 1]['until_hours'];
    }

    /**
     * The most variants of one channel that one run of the job reads.
     */
    public static function variantsPerRun(): int
    {
        return min(self::int('variants_per_run', 25, 1), 500);
    }

    /**
     * The share of a channel's rate limit that reading metrics may use, in
     * percent. Never all of it: publishing needs the rest.
     */
    public static function rateLimitPercent(): int
    {
        return min(self::int('rate_limit_percent', 20, 1), 90);
    }

    /**
     * Seconds a channel is left alone after the platform refused a read.
     */
    public static function pauseSeconds(): int
    {
        return min(self::int('pause_minutes', 60, 1) * 60, self::LONGEST_PAUSE_SECONDS);
    }

    /**
     * The time limit of one run of the job.
     */
    public static function jobSeconds(): int
    {
        return self::int('timeout', 45, 5);
    }

    /**
     * How long the lock on a channel lives. Longer than the job, so it
     * cannot expire while its holder is alive.
     */
    public static function lockSeconds(): int
    {
        return self::jobSeconds() + self::LOCK_SLACK_SECONDS;
    }

    /**
     * The weight of a metric in the engagement score. Reach has none.
     */
    public static function weight(Metric $metric): float
    {
        $default = match ($metric) {
            Metric::Reactions, Metric::Clicks => 1.0,
            Metric::Comments => 2.0,
            Metric::Shares => 3.0,
            Metric::Reach => 0.0,
        };

        if ($metric === Metric::Reach) {
            return $default;
        }

        $value = config("noongun.insights.score.{$metric->value}");

        return is_numeric($value) && (float) $value >= 0 && (float) $value <= 1000 ? round((float) $value, 4) : $default;
    }

    private static function int(string $key, int $default, int $min): int
    {
        $value = config("noongun.insights.{$key}");

        return max($min, is_numeric($value) ? (int) $value : $default);
    }
}
