<?php

namespace App\Domain\Drafting\Console;

use App\Domain\Drafting\DraftingLimits;
use App\Domain\Drafting\DraftRunStatus;
use App\Domain\Drafting\GenerateDraftJob;
use App\Domain\Drafting\Models\DraftRun;
use App\Domain\Workspaces\CurrentWorkspace;
use App\Domain\Workspaces\Models\Workspace;
use Illuminate\Console\Command;
use Throwable;

/**
 * Closes draft runs that will never finish by themselves: a run that has
 * been running for longer than any job may take (its worker died), and a
 * run that has been pending for longer than any job waits (its job was
 * lost). Each is marked failed with a reason the person can read.
 *
 * It never asks a provider again. A run whose worker died may have been
 * paid for already, and whether to spend again is for a person to decide:
 * proposing the week again fills the times that are still open.
 */
class RecoverStuckDraftRuns extends Command
{
    public const NEVER_STARTED = 'The draft was never picked up by a worker, so it was not generated. Nothing was spent. Check that the queue is running, then propose the week again.';

    protected $signature = 'noongun:recover-stuck-draft-runs';

    protected $description = 'Mark draft runs that lost their worker as failed';

    public function handle(CurrentWorkspace $currentWorkspace): int
    {
        $runningSince = now()->subSeconds(DraftingLimits::stuckAfterSeconds());
        $pendingSince = now()->subSeconds(DraftingLimits::unclaimedAfterSeconds());
        $closed = 0;
        $errors = 0;

        foreach (Workspace::query()->lazyById(200) as $workspace) {
            try {
                $closed += $currentWorkspace->run($workspace, function () use ($runningSince, $pendingSince): int {
                    $interrupted = DraftRun::query()
                        ->inStatus(DraftRunStatus::Running)
                        ->where('started_at', '<=', $runningSince)
                        ->orderBy('id')
                        ->get()
                        ->filter(fn (DraftRun $run): bool => $run->markFailed(GenerateDraftJob::INTERRUPTED));

                    $unclaimed = DraftRun::query()
                        ->inStatus(DraftRunStatus::Pending)
                        ->where('created_at', '<=', $pendingSince)
                        ->orderBy('id')
                        ->get()
                        ->filter(fn (DraftRun $run): bool => $run->markFailed(self::NEVER_STARTED));

                    return $interrupted->count() + $unclaimed->count();
                });
            } catch (Throwable $e) {
                $errors++;
                report($e);
            }
        }

        $this->info("Closed {$closed} stuck draft run(s).");

        return $errors === 0 ? self::SUCCESS : self::FAILURE;
    }
}
