<?php

namespace App\Domain\Drafting;

/**
 * Typed access to config('noongun.drafting.limits'): what "Propose a week"
 * may spend and how its job behaves. Values that make no sense (no posts,
 * zero attempts, an empty backoff list) are replaced by safe ones.
 */
final class DraftingLimits
{
    /**
     * How many answers one run can pay for: a generator asks once more
     * when the first answer is invalid (ADR 0011).
     */
    public const ANSWERS_PER_RUN = 2;

    /** Seconds on top of the provider calls for a job's own work. */
    public const JOB_SLACK_SECONDS = 15;

    /** Seconds a run usually waits for a worker at most, on a queue that runs. */
    public const SLOW_AFTER_SECONDS = 120;

    /** The most posts one proposal drafts. */
    public static function postsPerProposal(): int
    {
        return self::int('posts_per_proposal', 14, 1);
    }

    /** The most draft runs a workspace may open in 24 hours. */
    public static function runsPerDay(): int
    {
        return self::int('runs_per_day', 50, 1);
    }

    /**
     * The most draft runs all workspaces together may open in 24 hours on
     * the key of the instance. Runs on a workspace's own key do not count.
     */
    public static function instanceRunsPerDay(): int
    {
        return self::int('instance_runs_per_day', 200, 1);
    }

    /**
     * How many times one planned post is asked for at most, counting the
     * first.
     */
    public static function maxAttempts(): int
    {
        return self::int('max_attempts', 3, 1);
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

    public static function longestBackoff(): int
    {
        return max(self::backoff());
    }

    /**
     * The time limit of the job that runs one draft run: every answer the
     * generator may wait for, plus a margin.
     */
    public static function jobSeconds(): int
    {
        return DraftingConfig::timeoutSeconds() * self::ANSWERS_PER_RUN + self::JOB_SLACK_SECONDS;
    }

    /**
     * A run that has been running for this long has lost its worker. Never
     * shorter than the time limit of the job, so a run that is still being
     * worked on is not given up.
     */
    public static function stuckAfterSeconds(): int
    {
        return max(self::int('stuck_after_minutes', 6, 1) * 60, self::jobSeconds() + 60);
    }

    /**
     * How long a run may wait for a worker before the page says that it is
     * taking longer than expected. $attempt is the try the run is for: a
     * retry waits out its backoff first.
     */
    public static function slowAfterSeconds(int $attempt = 1): int
    {
        return self::SLOW_AFTER_SECONDS + ($attempt > 1 ? self::backoffBefore($attempt - 1) : 0);
    }

    /**
     * A run that has been pending for this long was never picked up. The
     * longest backoff is added because a run that waits for its retry is
     * pending by design.
     */
    public static function unclaimedAfterSeconds(): int
    {
        return self::stuckAfterSeconds() + self::longestBackoff();
    }

    /**
     * How many tokens one answer usually takes, thinking included, for the
     * expected cost of a proposal.
     */
    public static function expectedOutputTokens(): int
    {
        return self::int('expected_output_tokens', 1500, 1);
    }

    /** How many weeks after the current one can be proposed. */
    public static function weeksAhead(): int
    {
        return self::int('weeks_ahead', 8, 0);
    }

    /**
     * @return non-empty-list<int>
     */
    private static function backoff(): array
    {
        $configured = config('noongun.drafting.limits.backoff');
        $backoff = [];

        foreach (is_array($configured) ? $configured : [] as $seconds) {
            if (is_numeric($seconds) && (int) $seconds > 0) {
                $backoff[] = (int) $seconds;
            }
        }

        return $backoff === [] ? [30] : $backoff;
    }

    private static function int(string $key, int $default, int $min): int
    {
        $value = config("noongun.drafting.limits.{$key}");

        return max($min, is_numeric($value) ? (int) $value : $default);
    }
}
