<?php

namespace App\Domain\Drafting\Actions;

use App\Domain\Drafting\DraftRunStatus;
use App\Domain\Drafting\Exceptions\DraftingNotAllowed;
use App\Domain\Drafting\Models\DraftRun;
use App\Domain\Workspaces\CurrentWorkspace;
use App\Domain\Workspaces\Models\Workspace;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Closes every draft run of the workspace that is still waiting or being
 * worked on, because a person chose to stop waiting. It is the way out when
 * the queue or the scheduler is down and nothing else will close them.
 *
 * Each run is marked failed through its own conditional update, so a run
 * that finished a moment earlier keeps its result. No provider is called
 * and nothing is queued. A job that arrives later for a stopped run finds
 * it settled and does nothing; a worker that was in the middle of a run
 * cannot record its result any more, so that answer is discarded.
 *
 * It takes the lock on the workspace row that StartDraftRuns takes, so a
 * proposal that starts at the same moment either is stopped whole or not
 * at all.
 */
final class StopOpenRuns
{
    public const STOPPED = 'Stopped before it was drafted, because someone chose to stop waiting. No draft was created. Propose the week again to fill this time.';

    public function __construct(private readonly CurrentWorkspace $currentWorkspace) {}

    /**
     * @param  User  $actor  an owner or editor of the workspace
     * @return int how many runs were closed
     *
     * @throws DraftingNotAllowed
     */
    public function handle(User $actor): int
    {
        $workspace = $this->currentWorkspace->getOrFail();

        if (! $actor->exists || ! ($workspace->roleOf($actor)?->canEditContent() ?? false)) {
            throw DraftingNotAllowed::for($actor, $workspace);
        }

        return DB::transaction(function () use ($workspace): int {
            Workspace::query()->lockForUpdate()->findOrFail($workspace->id);

            return DraftRun::query()
                ->inStatus(DraftRunStatus::Pending, DraftRunStatus::Running)
                ->orderBy('id')
                ->get()
                ->filter(fn (DraftRun $run): bool => $run->markFailed(self::STOPPED))
                ->count();
        });
    }
}
