<?php

namespace App\Domain\Channels\Console;

use App\Domain\Channels\ChannelManager;
use App\Domain\Channels\ChannelStatus;
use App\Domain\Channels\CheckChannelTokenJob;
use App\Domain\Channels\Models\Channel;
use App\Domain\Channels\TokenCheckConfig;
use App\Domain\Workspaces\CurrentWorkspace;
use App\Domain\Workspaces\Models\Workspace;
use Illuminate\Console\Command;
use Throwable;

/**
 * Run by the scheduler every hour. Queues a CheckChannelTokenJob for each
 * active channel whose token was not checked in the last
 * TokenCheckConfig::everyHours(), at most
 * TokenCheckConfig::channelsPerRun() in one run. So every channel is
 * checked about once a day, an instance with many channels spreads its
 * checks over the hours, and no run queues more than a bounded number.
 *
 * It calls no platform and changes no channel. A channel that is not
 * active is not checked: a pending or disconnected one is not connected,
 * and one that needs a reconnect is waiting for its user.
 */
class CheckChannelTokens extends Command
{
    protected $signature = 'noongun:check-channel-tokens';

    protected $description = 'Queue a check of the tokens of connected channels that is due';

    public function handle(CurrentWorkspace $currentWorkspace, ChannelManager $channels): int
    {
        if (! TokenCheckConfig::enabled()) {
            $this->info('Checking tokens is turned off.');

            return self::SUCCESS;
        }

        $due = now()->subHours(TokenCheckConfig::everyHours());
        $left = TokenCheckConfig::channelsPerRun();
        $queued = 0;
        $errors = 0;

        foreach (Workspace::query()->lazyById(200) as $workspace) {
            if ($left < 1) {
                break;
            }

            try {
                $queuedHere = $currentWorkspace->run($workspace, function () use ($channels, $due, $left): int {
                    $queued = 0;

                    $candidates = Channel::query()
                        ->where('status', ChannelStatus::Active)
                        ->where(fn ($query) => $query->whereNull('token_checked_at')->orWhere('token_checked_at', '<=', $due))
                        ->orderByRaw('token_checked_at asc nulls first')
                        ->orderBy('id')
                        ->limit($left)
                        ->get();

                    foreach ($candidates as $channel) {
                        if (! $channels->has($channel->platform)) {
                            continue;
                        }

                        // Noted before the job runs, so a channel whose
                        // check is still waiting does not take a place in
                        // the next run. The job notes it again when it asks.
                        Channel::query()->whereKey($channel->id)->toBase()->update(['token_checked_at' => now()]);

                        dispatch(new CheckChannelTokenJob($channel->workspace_id, $channel->id));
                        $queued++;
                    }

                    return $queued;
                });

                $queued += $queuedHere;
                $left -= $queuedHere;
            } catch (Throwable $e) {
                $errors++;
                report($e);
            }
        }

        $this->info("Queued a token check for {$queued} channel(s).");

        return $errors === 0 ? self::SUCCESS : self::FAILURE;
    }
}
