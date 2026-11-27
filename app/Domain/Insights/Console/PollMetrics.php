<?php

namespace App\Domain\Insights\Console;

use App\Domain\Channels\ChannelManager;
use App\Domain\Channels\ChannelStatus;
use App\Domain\Channels\Models\Channel;
use App\Domain\Insights\ChannelPolling;
use App\Domain\Insights\DueVariants;
use App\Domain\Insights\FetchMetricsJob;
use App\Domain\Insights\InsightsConfig;
use App\Domain\Scheduling\Exceptions\LockStoreNotShared;
use App\Domain\Scheduling\VariantLock;
use App\Domain\Workspaces\CurrentWorkspace;
use App\Domain\Workspaces\Models\Workspace;
use Illuminate\Console\Command;
use Throwable;

/**
 * Run by the scheduler. For each workspace it finds the active channels
 * whose platform gives metrics and that have a published variant due for a
 * reading, and queues one FetchMetricsJob for each.
 *
 * It calls no platform and writes nothing. Which variants are read, and how
 * many, is decided again by the job under the lock of the channel, so
 * running this twice only queues a job that finds nothing to do.
 */
class PollMetrics extends Command
{
    protected $signature = 'noongun:poll-metrics';

    protected $description = 'Queue a reading of the metrics of published posts that are due';

    public function handle(CurrentWorkspace $currentWorkspace, ChannelManager $channels, DueVariants $due): int
    {
        if (! InsightsConfig::enabled()) {
            $this->info('Reading metrics is turned off.');

            return self::SUCCESS;
        }

        try {
            VariantLock::assertStoreIsShared();
        } catch (LockStoreNotShared $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $now = now();
        $queued = 0;
        $errors = 0;

        foreach (Workspace::query()->lazyById(200) as $workspace) {
            try {
                $queued += $currentWorkspace->run($workspace, function () use ($channels, $due, $now): int {
                    $queued = 0;

                    foreach (Channel::query()->where('status', ChannelStatus::Active)->orderBy('id')->get() as $channel) {
                        if (! $channel->canPublish()
                            || $channels->metricsReader($channel) === null
                            || ChannelPolling::isPaused($channel->id)
                            || ! $due->exist($channel, $now)) {
                            continue;
                        }

                        dispatch(new FetchMetricsJob($channel->workspace_id, $channel->id));
                        $queued++;
                    }

                    return $queued;
                });
            } catch (Throwable $e) {
                $errors++;
                report($e);
            }
        }

        $this->info("Queued a reading for {$queued} channel(s).");

        return $errors === 0 ? self::SUCCESS : self::FAILURE;
    }
}
