<?php

namespace App\Domain\Insights;

use App\Domain\Channels\Contracts\Metric;
use App\Domain\Channels\Contracts\MetricReading;

/**
 * The one number posts are compared by, defined here and nowhere else:
 *
 *     score = reactions x w1 + comments x w2 + shares x w3 + clicks x w4
 *
 * with the weights from config('noongun.insights.score'). A number the
 * platform did not give counts as nothing. When it gave none of the four,
 * there is no score (null), which is not the same as a score of 0.
 *
 * Reach is deliberately not part of it. Dividing by reach would give a rate
 * that cannot be compared with a post whose reach is unknown, and reach
 * needs a permission that many channels do not have. The score is additive:
 * the score of a post is the sum of the scores of its variants.
 *
 * Scores compare posts of one channel, or of channels that give the same
 * numbers. A channel without clicks scores lower for the same post than one
 * with them.
 *
 * Ranking posts against each other needs more care than showing a number,
 * and has a score of its own here, the comparable score: reactions,
 * comments and shares with the same weights, and never clicks or reach.
 * Clicks need a permission a channel can gain or lose, and a post is not
 * read any more after the last step of the cadence. So of two posts of one
 * channel one can have clicks and the other never will, and a score with
 * clicks would rank by when the permission was added. The three numbers
 * that are left are the ones every measured post of a channel has.
 *
 * Two more things make a ranking fair, and are settings here: a post is
 * compared only when it has been out for minAgeHours(), because a post of
 * two hours has not had its readers yet, and nothing is said to have done
 * well until a channel has minMeasuredPosts() such posts to compare.
 */
final class EngagementScore
{
    private const COUNTED = [Metric::Reactions, Metric::Comments, Metric::Shares, Metric::Clicks];

    /**
     * The same formula over the columns of metric_snapshots, for ordering
     * and limiting in the database. Null where of() gives null. It takes
     * the four weights as bindings, see bindings().
     */
    public const SQL = '(case when coalesce(metric_snapshots.reactions, metric_snapshots.comments, metric_snapshots.shares, metric_snapshots.clicks) is null then null'
        .' else round(coalesce(metric_snapshots.reactions, 0) * ?::numeric + coalesce(metric_snapshots.comments, 0) * ?::numeric'
        .' + coalesce(metric_snapshots.shares, 0) * ?::numeric + coalesce(metric_snapshots.clicks, 0) * ?::numeric, 4) end)';

    private const COMPARABLE = [Metric::Reactions, Metric::Comments, Metric::Shares];

    /**
     * The comparable score over the columns of metric_snapshots: null when
     * none of the three numbers was given. It takes three weights as
     * bindings, see comparableBindings().
     */
    public const COMPARABLE_SQL = '(case when coalesce(metric_snapshots.reactions, metric_snapshots.comments, metric_snapshots.shares) is null then null'
        .' else round(coalesce(metric_snapshots.reactions, 0) * ?::numeric + coalesce(metric_snapshots.comments, 0) * ?::numeric'
        .' + coalesce(metric_snapshots.shares, 0) * ?::numeric, 4) end)';

    /** The most hours a post can be asked to have been out before it is compared, whatever is configured. */
    public const MAX_MIN_AGE_HOURS = 720;

    public static function of(MetricReading|MetricTotals $numbers): ?float
    {
        $score = null;

        foreach (self::COUNTED as $metric) {
            $value = $numbers->get($metric);

            if ($value !== null) {
                $score = ($score ?? 0.0) + $value * InsightsConfig::weight($metric);
            }
        }

        return $score === null ? null : round($score, 4);
    }

    /**
     * The weights in the order SQL takes them: the bindings that every use
     * of SQL needs, once for each time SQL appears in a statement.
     *
     * @return list<float>
     */
    public static function bindings(): array
    {
        return array_map(InsightsConfig::weight(...), self::COUNTED);
    }

    /**
     * The comparable score of one set of numbers: what COMPARABLE_SQL gives
     * for the same row.
     */
    public static function comparable(MetricReading|MetricTotals $numbers): ?float
    {
        $score = null;

        foreach (self::COMPARABLE as $metric) {
            $value = $numbers->get($metric);

            if ($value !== null) {
                $score = ($score ?? 0.0) + $value * InsightsConfig::weight($metric);
            }
        }

        return $score === null ? null : round($score, 4);
    }

    /**
     * The weights in the order COMPARABLE_SQL takes them, once for each
     * time it appears in a statement.
     *
     * @return list<float>
     */
    public static function comparableBindings(): array
    {
        return array_map(InsightsConfig::weight(...), self::COMPARABLE);
    }

    /**
     * How many hours a post must have been out before it is compared with
     * others (config noongun.insights.min_age_hours). 0 compares a post
     * from its first reading.
     */
    public static function minAgeHours(): int
    {
        $hours = config('noongun.insights.min_age_hours');

        return min(self::MAX_MIN_AGE_HOURS, max(0, is_numeric($hours) ? (int) $hours : 48));
    }

    /**
     * How many measured posts of that age a channel must have in the
     * window before any of them is said to have done well
     * (config noongun.insights.min_measured_posts). Never fewer than 2:
     * one post cannot be above its own average.
     */
    public static function minMeasuredPosts(): int
    {
        $posts = config('noongun.insights.min_measured_posts');

        return min(1000, max(2, is_numeric($posts) ? (int) $posts : 5));
    }
}
