<?php

namespace Tests\Fakes;

use App\Domain\Channels\Contracts\ChannelCapabilities;
use App\Domain\Channels\Contracts\Metric;
use App\Domain\Channels\Contracts\MetricReading;
use App\Domain\Channels\Contracts\RateLimit;
use App\Domain\Channels\Contracts\ReadsMetrics;
use App\Domain\Channels\Exceptions\ChannelException;
use App\Domain\Channels\Models\Channel;
use App\Domain\Content\Models\PostVariant;
use Closure;
use PHPUnit\Framework\Assert;
use Throwable;

/**
 * A FakeChannelAdapter that also reads metrics, without HTTP calls.
 *
 *     $fake = fakeMetricsAdapter();                       // registered for "facebook"
 *     $fake->reads($variant, ['reactions' => 12, 'comments' => 3, 'shares' => 1]);
 *     $fake->failMetricsRetryable(retryAfterSeconds: 600);
 *     ...
 *     $fake->assertMetricsFetchedTimes(1);
 *
 * By default it gives all five metrics for every channel, and a variant
 * that was not given numbers with reads() or readsUsing() gets no reading.
 * Register it with fakeMetricsAdapter() from tests/Support/insights.php.
 */
class FakeMetricsAdapter extends FakeChannelAdapter implements ReadsMetrics
{
    /** @var list<list<int>> the variant ids of each fetchMetrics() call, in order */
    public array $metricBatches = [];

    /** @var list<int> the channel id of each fetchMetrics() call, in order */
    public array $metricChannels = [];

    /** @var array<int, MetricReading> keyed by variant id */
    private array $readings = [];

    /** @var array<int, MetricReading> keyed by variant id, returned whether or not it was asked for */
    private array $unasked = [];

    /** @var (Closure(PostVariant, int): (MetricReading|null))|null */
    private ?Closure $readUsing = null;

    /** @var list<Throwable> */
    private array $metricFailures = [];

    /** @var list<Metric>|null null means every metric in the capabilities */
    private ?array $channelMetrics = null;

    private int $callsPerVariant = 1;

    public function __construct(string $platform = 'facebook')
    {
        parent::__construct($platform);

        $this->givesMetrics(Metric::cases());
    }

    public function metricsFor(Channel $channel): array
    {
        return $this->channelMetrics ?? $this->capabilities()->metrics;
    }

    public function metricCallsPerVariant(): int
    {
        return $this->callsPerVariant;
    }

    public function fetchMetrics(Channel $channel, array $variants): array
    {
        $this->metricBatches[] = array_map(fn (PostVariant $variant): int => $variant->id, $variants);
        $this->metricChannels[] = $channel->id;

        if ($this->metricFailures !== []) {
            throw array_shift($this->metricFailures);
        }

        $call = count($this->metricBatches);
        $readings = [];

        foreach ($variants as $variant) {
            $reading = $this->readUsing !== null
                ? ($this->readUsing)($variant, $call)
                : ($this->readings[$variant->id] ?? null);

            if ($reading !== null) {
                $readings[$variant->id] = $reading;
            }
        }

        return $this->unasked + $readings;
    }

    // Configuration

    /**
     * The metrics the platform can give at all. An empty list makes this a
     * platform without metrics, although the adapter can read them.
     *
     * @param  list<Metric>  $metrics
     */
    public function givesMetrics(array $metrics): static
    {
        $current = $this->capabilities();

        return $this->withCapabilities(new ChannelCapabilities(
            maxBodyLength: $current->maxBodyLength,
            mediaTypes: $current->mediaTypes,
            maxMediaItems: $current->maxMediaItems,
            linkPreviews: $current->linkPreviews,
            rateLimit: $current->rateLimit,
            linkTitleLength: $current->linkTitleLength,
            linkDescriptionLength: $current->linkDescriptionLength,
            metrics: $metrics,
        ));
    }

    /**
     * The metrics the token of every channel can read, as when a permission
     * is missing. Null gives back everything in the capabilities.
     *
     * @param  list<Metric>|null  $metrics
     */
    public function channelsMayRead(?array $metrics): static
    {
        $this->channelMetrics = $metrics;

        return $this;
    }

    public function withRateLimit(?RateLimit $rateLimit, int $callsPerVariant = 1): static
    {
        $current = $this->capabilities();
        $this->callsPerVariant = $callsPerVariant;

        return $this->withCapabilities(new ChannelCapabilities(
            maxBodyLength: $current->maxBodyLength,
            mediaTypes: $current->mediaTypes,
            maxMediaItems: $current->maxMediaItems,
            linkPreviews: $current->linkPreviews,
            rateLimit: $rateLimit,
            linkTitleLength: $current->linkTitleLength,
            linkDescriptionLength: $current->linkDescriptionLength,
            metrics: $current->metrics,
        ));
    }

    /**
     * What the platform reports for a variant from now on. The array is
     * keyed by metric name; a metric that is left out is null.
     *
     * @param  MetricReading|array<string, int|null>  $reading
     */
    public function reads(PostVariant $variant, MetricReading|array $reading): static
    {
        $this->readings[$variant->id] = $reading instanceof MetricReading ? $reading : new MetricReading(...$reading);

        return $this;
    }

    /**
     * A reading that every fetchMetrics() call returns, also when the
     * variant was not asked about, as a faulty adapter might.
     *
     * @param  MetricReading|array<string, int|null>  $reading
     */
    public function alsoReturns(PostVariant $variant, MetricReading|array $reading): static
    {
        $this->unasked[$variant->id] = $reading instanceof MetricReading ? $reading : new MetricReading(...$reading);

        return $this;
    }

    /**
     * Decide each reading. The closure gets the variant and the number of
     * this fetchMetrics() call, starting at 1, and returns null for a
     * variant without a reading.
     *
     * @param  Closure(PostVariant, int): (MetricReading|null)  $callback
     */
    public function readsUsing(Closure $callback): static
    {
        $this->readUsing = $callback;

        return $this;
    }

    /**
     * The next $times fetchMetrics() calls throw a "limiting requests"
     * failure, then reading works again.
     */
    public function failMetricsRetryable(int $times = 1, ?int $retryAfterSeconds = null, string $message = 'The platform is limiting requests.'): static
    {
        return $this->failNextMetrics(ChannelException::retryable($this->platform(), $message, $retryAfterSeconds), $times);
    }

    public function failMetricsPermanently(int $times = 1, string $message = 'The platform refused the request.'): static
    {
        return $this->failNextMetrics(ChannelException::permanent($this->platform(), $message), $times);
    }

    public function failMetricsReconnectRequired(int $times = 1, string $message = 'The access token is no longer valid.'): static
    {
        return $this->failNextMetrics(ChannelException::reconnectRequired($this->platform(), $message), $times);
    }

    public function failNextMetrics(Throwable $failure, int $times = 1): static
    {
        for ($i = 0; $i < $times; $i++) {
            $this->metricFailures[] = $failure;
        }

        return $this;
    }

    // Inspection

    /**
     * How many fetchMetrics() calls were made. Failed calls count.
     */
    public function metricsFetchCount(): int
    {
        return count($this->metricBatches);
    }

    public function assertMetricsFetchedTimes(int $times): static
    {
        Assert::assertSame($times, $this->metricsFetchCount(), "Expected {$times} fetchMetrics call(s), got {$this->metricsFetchCount()}.");

        return $this;
    }

    public function assertNoMetricsFetched(): static
    {
        return $this->assertMetricsFetchedTimes(0);
    }

    /**
     * The variant ids of the last fetchMetrics() call.
     *
     * @return list<int>
     */
    public function lastMetricBatch(): array
    {
        return $this->metricBatches === [] ? [] : $this->metricBatches[count($this->metricBatches) - 1];
    }
}
