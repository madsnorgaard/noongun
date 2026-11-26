<?php

namespace App\Domain\Channels\Contracts;

use App\Domain\Channels\Exceptions\ChannelException;
use App\Domain\Channels\Models\Channel;
use App\Domain\Content\Models\PostVariant;

/**
 * For an adapter whose platform lets Noon Gun read the numbers of its own
 * published posts. An adapter that cannot (LinkedIn until M9, ADR 0007)
 * does not implement this and lists no metrics in its capabilities: nothing
 * is asked of it, nothing fails and no zero is invented. See ADR 0016.
 *
 * Callers do not test for this interface themselves. They ask
 * ChannelManager::metricsReader(), which also checks the capabilities.
 *
 * Reading is safe to repeat and changes nothing on the platform. It only
 * ever concerns posts this workspace published through this channel.
 */
interface ReadsMetrics
{
    /**
     * The metrics the token of this channel can read, a subset of
     * ChannelCapabilities::$metrics. A metric that needs a permission the
     * channel was not granted is left out; fetchMetrics() then reports it as
     * null. Makes no platform call.
     *
     * @return list<Metric>
     */
    public function metricsFor(Channel $channel): array;

    /**
     * The most calls that reading one variant counts against the rate limit
     * in ChannelCapabilities::$rateLimit, however the adapter batches them.
     * The polling job uses it to stay inside the share of that limit it is
     * allowed, so publishing always has calls left. At least 1.
     */
    public function metricCallsPerVariant(): int;

    /**
     * Reads the current numbers of many published variants of one channel,
     * batched as the platform allows. It writes nothing: the caller stores
     * the readings.
     *
     * The answer is keyed by variant id. A variant without an entry gave no
     * reading this time, for example because the post was deleted on the
     * platform or its part of a batch failed; that is not an error, and the
     * caller asks again when the variant is next due. A metric the platform
     * did not give is null in the reading, never zero.
     *
     * A failure of the whole call is a ChannelException: retryable, with
     * retryAfterSeconds when the platform is limiting requests, or
     * requiresReconnect when the token is no longer accepted. A permission
     * that is only missing for some metrics is not a failure: those metrics
     * are null.
     *
     * @param  list<PostVariant>  $variants  published variants of $channel, each with its channel relation set, at most the caller's batch size
     * @return array<int, MetricReading> keyed by variant id
     *
     * @throws ChannelException
     */
    public function fetchMetrics(Channel $channel, array $variants): array;
}
