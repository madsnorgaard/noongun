<?php

namespace App\Domain\Channels\Console;

use App\Domain\Channels\Models\Channel;
use App\Domain\Workspaces\CurrentWorkspace;
use App\Domain\Workspaces\Models\Workspace;
use Illuminate\Console\Command;
use Throwable;

/**
 * Removes accounts that a connect returned and nobody confirmed within
 * Channel::PENDING_MINUTES: a closed tab, an expired session, a user who
 * walked away. Their tokens are erased with them. A pending channel can
 * never publish, so this is about not keeping access the user did not
 * confirm, not about stopping posts.
 */
class DiscardPendingChannels extends Command
{
    protected $signature = 'noongun:discard-pending-channels';

    protected $description = 'Remove connected accounts that were never confirmed';

    public function handle(CurrentWorkspace $currentWorkspace): int
    {
        $discarded = 0;
        $errors = 0;

        foreach (Workspace::query()->lazyById(200) as $workspace) {
            try {
                $discarded += $currentWorkspace->run($workspace, fn (): int => Channel::discardExpiredPending());
            } catch (Throwable $e) {
                $errors++;
                report($e);
            }
        }

        $this->info("Discarded {$discarded} unconfirmed channel(s).");

        return $errors === 0 ? self::SUCCESS : self::FAILURE;
    }
}
