<?php

namespace App\Domain\Channels\Contracts;

/**
 * The numbers Noon Gun reads about a published post. The value is the name
 * of the column in metric_snapshots and the key in every array of metrics.
 *
 * What each one means on a platform is written down by its adapter and in
 * docs/platform-notes/. A platform that has no such number leaves it out of
 * ChannelCapabilities::$metrics; it is never reported as zero.
 */
enum Metric: string
{
    case Reactions = 'reactions';
    case Comments = 'comments';
    case Shares = 'shares';
    case Clicks = 'clicks';
    case Reach = 'reach';
}
