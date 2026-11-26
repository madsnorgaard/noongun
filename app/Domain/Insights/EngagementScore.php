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
}
