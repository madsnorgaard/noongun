<?php

namespace App\Domain\Drafting\Actions;

use App\Domain\Drafting\DraftGenerators;
use App\Domain\Drafting\Exceptions\DraftingNotAllowed;
use App\Domain\Drafting\Exceptions\ProposalRefused;
use App\Domain\Drafting\GenerateDraftJob;
use App\Domain\Drafting\Models\Brief;
use App\Domain\Drafting\Models\DraftRun;
use App\Domain\Drafting\Planning\ProposalChecks;
use App\Domain\Drafting\Planning\RunToOpen;
use App\Domain\Workspaces\CurrentWorkspace;
use App\Domain\Workspaces\Models\Workspace;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * The one way drafting is started by a person: opens one pending draft run
 * for each post a plan asks for and queues one job for each run. A proposed
 * week (ProposeWeek) and one post from an idea (DraftFromIdea) both go
 * through here, so the checks, the limits and the lock are the same for
 * both. It calls no provider itself; GenerateDraftJob does, one run at a
 * time, and what comes out is a draft that a person approves or not.
 *
 * Everything is checked before the first run is opened, inside one
 * transaction that first locks the workspace row. Two requests for the
 * same workspace therefore go one after the other, and the second one
 * finds the runs of the first and is refused: two clicks cannot both
 * start, and the limits cannot be passed by asking twice at once.
 *
 * The runs of one start share request['proposal']. Each also notes its
 * attempt.
 */
final class StartDraftRuns
{
    public const NOT_QUEUED = 'The draft could not be handed to the queue, so it was not drafted. Nothing was spent. Propose the week again.';

    public function __construct(
        private readonly ProposalChecks $checks,
        private readonly DraftGenerators $generators,
        private readonly CurrentWorkspace $currentWorkspace,
    ) {}

    /**
     * @param  User  $actor  the human who asked; an owner or editor of the workspace
     * @param  bool  $needsSlot  whether a channel that can publish must have a slot, as a week needs
     * @param  Closure(Workspace): non-empty-list<RunToOpen>  $plan  works out what to draft, under the lock; it throws ProposalRefused when there is nothing to start
     * @return list<DraftRun> the runs that were opened, in the order of the plan
     *
     * @throws DraftingNotAllowed
     * @throws ProposalRefused when nothing was started; the message says why
     */
    public function handle(User $actor, bool $needsSlot, Closure $plan): array
    {
        $workspace = $this->currentWorkspace->getOrFail();

        if (! $actor->exists || ! ($workspace->roleOf($actor)?->canEditContent() ?? false)) {
            throw DraftingNotAllowed::for($actor, $workspace);
        }

        $runs = DB::transaction(function () use ($actor, $workspace, $needsSlot, $plan): array {
            // One start at a time for a workspace, from here to the commit.
            Workspace::query()->lockForUpdate()->findOrFail($workspace->id);

            foreach ($this->checks->missingSetup($workspace, $needsSlot) as $refusal) {
                throw $refusal;
            }

            $planned = $plan($workspace);

            foreach ($this->checks->overLimits($workspace, count($planned)) as $refusal) {
                throw $refusal;
            }

            $context = $this->generators->contextFor($workspace);
            $brief = Brief::current();
            $proposal = (string) Str::uuid();
            $runs = [];

            foreach ($planned as $run) {
                $runs[] = DraftRun::start($actor, $context, [
                    'proposal' => $proposal,
                    ...$run->note,
                    'attempt' => 1,
                ], $brief, $run->pillar, $run->idea);
            }

            return $runs;
        });

        foreach ($runs as $run) {
            try {
                dispatch(new GenerateDraftJob($workspace->id, $run->id));
            } catch (Throwable $failure) {
                // The exception itself is not logged: a queue connection
                // failure can carry its address and password.
                Log::error('A draft run could not be queued.', [
                    'workspace_id' => $workspace->id,
                    'draft_run_id' => $run->id,
                    'exception' => $failure::class,
                ]);

                $run->markFailed(self::NOT_QUEUED);
            }
        }

        return $runs;
    }
}
