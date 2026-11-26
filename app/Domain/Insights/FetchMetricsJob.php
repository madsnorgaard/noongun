<?php

namespace App\Domain\Insights;

use App\Domain\Channels\ChannelManager;
use App\Domain\Channels\Contracts\ChannelAdapter;
use App\Domain\Channels\Contracts\Metric;
use App\Domain\Channels\Contracts\MetricReading;
use App\Domain\Channels\Contracts\ReadsMetrics;
use App\Domain\Channels\Exceptions\ChannelException;
use App\Domain\Channels\Models\Channel;
use App\Domain\Content\Models\PostVariant;
use App\Domain\Insights\Models\MetricSnapshot;
use App\Domain\Workspaces\CurrentWorkspace;
use App\Domain\Workspaces\Models\Workspace;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Reads the numbers of the published variants of one channel that are due,
 * in one call to the adapter, and stores what changed. One job per channel,
 * because rate limits are per channel.
 *
 * It carries two ids and nothing else: no token and no post text is in the
 * payload, and nothing from the platform is logged but the safe message of
 * a ChannelException.
 *
 * It only ever reads. It cannot change a post, a variant's outcome or the
 * status of a channel, whatever the platform answers: a refusal is logged,
 * the channel is left alone for a while (ChannelPolling::pause()), and the
 * next run of the scheduler tries again. A channel that is not active, a
 * platform without metrics, a pause and a lock that is taken are all a
 * quiet return.
 *
 * Running it twice is harmless: the second run finds nothing due, and a
 * reading that repeats the stored numbers adds no row. The queue never
 * runs it again by itself. While one is queued or running for a channel, a
 * second one for that channel is not queued at all, so the queue holds at
 * most one for each channel however long the workers or a platform are
 * away.
 *
 * A post the platform reports as gone is counted, and after
 * InsightsConfig::goneAfter() such answers in a row DueVariants leaves it
 * out. A reading sets the count back.
 *
 * Publishing comes first. This job runs on its own queue, spends at most a
 * share of the channel's rate limit, and stops for the time the platform
 * asks when it says it is limiting requests. See ADR 0016.
 */
final class FetchMetricsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout;

    public bool $failOnTimeout = true;

    public function __construct(
        public int $workspaceId,
        public int $channelId,
    ) {
        $this->timeout = InsightsConfig::jobSeconds();
        $this->onQueue(InsightsConfig::queue());
    }

    public function uniqueId(): string
    {
        return $this->workspaceId.':'.$this->channelId;
    }

    public function uniqueFor(): int
    {
        return InsightsConfig::UNIQUE_SECONDS;
    }

    public function handle(CurrentWorkspace $currentWorkspace, ChannelManager $channels, DueVariants $due): void
    {
        if (! InsightsConfig::enabled()) {
            return;
        }

        $workspace = Workspace::query()->find($this->workspaceId);

        if ($workspace === null) {
            return;
        }

        $currentWorkspace->run($workspace, function () use ($channels, $due): void {
            $this->read($channels, $due);
        });
    }

    private function read(ChannelManager $channels, DueVariants $due): void
    {
        $channel = Channel::query()->find($this->channelId);

        // Not connected, waiting for a reconnect or without a usable token:
        // nothing to do, and nothing wrong.
        if ($channel === null || ! $channel->canPublish()) {
            return;
        }

        $reader = $channels->metricsReader($channel);

        if ($reader === null || ChannelPolling::isPaused($channel->id)) {
            return;
        }

        $metrics = array_values(array_filter(
            $reader->metricsFor($channel),
            fn (Metric $metric): bool => $reader->capabilities()->gives($metric),
        ));

        if ($metrics === []) {
            return;
        }

        $lock = ChannelPolling::lock($channel->id);

        if (! $lock->get()) {
            return;
        }

        try {
            $limit = $reader->capabilities()->rateLimit;
            $callsPerVariant = max(1, $reader->metricCallsPerVariant());
            $affordable = intdiv(ChannelPolling::callsLeft($channel->id, $limit), $callsPerVariant);

            /** @var list<PostVariant> $variants */
            $variants = $due->for($channel, min(InsightsConfig::variantsPerRun(), $affordable))
                ->each(fn (PostVariant $variant) => $variant->setRelation('channel', $channel))
                ->values()
                ->all();

            if ($variants === []) {
                return;
            }

            ChannelPolling::spend($channel->id, $limit, count($variants) * $callsPerVariant);

            $readings = $this->ask($reader, $channel, $variants);

            if ($readings === null) {
                return;
            }

            $now = now();
            $gone = [];
            $back = [];

            foreach ($variants as $variant) {
                $reading = $readings[$variant->id] ?? null;

                if ($reading === null) {
                    continue;
                }

                if ($reading->gone) {
                    $gone[] = $variant->id;

                    continue;
                }

                MetricSnapshot::record($variant, $reading->only($metrics), $now);

                if ($variant->metrics_gone_count > 0) {
                    $back[] = $variant->id;
                }
            }

            // Asked, with or without an answer: due again after the step of
            // the cadence. Written without the model, so nothing else on the
            // row moves, not even updated_at.
            PostVariant::query()
                ->whereKey(array_map(fn (PostVariant $variant): int => $variant->id, $variants))
                ->toBase()
                ->update(['metrics_checked_at' => $now]);

            if ($gone !== []) {
                PostVariant::query()->whereKey($gone)->toBase()->increment('metrics_gone_count');
            }

            if ($back !== []) {
                PostVariant::query()->whereKey($back)->toBase()->update(['metrics_gone_count' => 0]);
            }
        } finally {
            $lock->release();
        }
    }

    /**
     * One call to the adapter. Null when it failed: the failure is logged
     * and the channel is paused, and that is all that happens.
     *
     * @param  list<PostVariant>  $variants
     * @return array<int, MetricReading>|null
     */
    private function ask(ChannelAdapter&ReadsMetrics $reader, Channel $channel, array $variants): ?array
    {
        try {
            return $reader->fetchMetrics($channel, $variants);
        } catch (ChannelException $e) {
            $pause = match (true) {
                // The token is not accepted for reading. Whether the channel
                // needs a reconnect is for publishing and the token check to
                // decide: a missing permission for metrics must never stop
                // a channel from publishing.
                $e->requiresReconnect => InsightsConfig::RECONNECT_PAUSE_SECONDS,
                default => max(InsightsConfig::pauseSeconds(), $e->retryAfterSeconds ?? 0),
            };

            if ($e->requiresReconnect) {
                ChannelPolling::pauseUntilConnectedAgain($channel->id, $pause);
            } else {
                ChannelPolling::pause($channel->id, $pause);
            }

            Log::warning('Reading the metrics of a channel failed. The channel is left alone for a while.', [
                'workspace_id' => $this->workspaceId,
                'channel_id' => $channel->id,
                'variants' => count($variants),
                'paused_seconds' => min($pause, InsightsConfig::LONGEST_PAUSE_SECONDS),
                'message' => $e->getMessage(),
                ...$e->context(),
            ]);
        } catch (Throwable $e) {
            // Not an answer from the platform. The exception itself is not
            // logged: HTTP client exceptions can carry tokens.
            ChannelPolling::pause($channel->id, InsightsConfig::pauseSeconds());

            Log::error('A channel adapter threw an unexpected exception while reading metrics.', [
                'workspace_id' => $this->workspaceId,
                'channel_id' => $channel->id,
                'exception' => $e::class,
            ]);
        }

        return null;
    }
}
