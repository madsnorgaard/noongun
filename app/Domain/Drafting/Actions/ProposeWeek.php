<?php

namespace App\Domain\Drafting\Actions;

use App\Domain\Drafting\DraftGenerators;
use App\Domain\Drafting\Exceptions\DraftingNotAllowed;
use App\Domain\Drafting\Exceptions\ProposalRefused;
use App\Domain\Drafting\GenerateDraftJob;
use App\Domain\Drafting\Models\Brief;
use App\Domain\Drafting\Models\DraftRun;
use App\Domain\Drafting\Planning\PlanWeek;
use App\Domain\Drafting\Planning\ProposalChecks;
use App\Domain\Workspaces\CurrentWorkspace;
use App\Domain\Workspaces\Models\Workspace;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Starts the drafting of one week: opens one pending draft run for each
 * post of the plan and queues one job for each run. It calls no provider
 * itself; GenerateDraftJob does, one run at a time, and what comes out is
 * a draft that a person approves or not.
 *
 * Everything is checked before the first run is opened, inside one
 * transaction that first locks the workspace row. Two requests for the
 * same workspace therefore go one after the other, and the second one
 * finds the runs of the first and is refused: two clicks cannot both
 * start a proposal, and the limits cannot be passed by asking twice at
 * once.
 *
 * The runs of one proposal share request['proposal']. Each also notes its
 * planned time, its channels, its slots and its attempt.
 */
final class ProposeWeek
{
    public const NOT_QUEUED = 'The draft could not be handed to the queue, so it was not drafted. Nothing was spent. Propose the week again.';

    public function __construct(
        private readonly PlanWeek $planWeek,
        private readonly ProposalChecks $checks,
        private readonly DraftGenerators $generators,
        private readonly CurrentWorkspace $currentWorkspace,
    ) {}

    /**
     * @param  User  $actor  the human who asked; an owner or editor of the workspace
     * @param  string  $week  a Monday on the workspace calendar, from PlanWeek::weekOf()
     * @param  string  $seen  WeekPlan::fingerprint() of the plan the person was shown
     * @return list<DraftRun> the runs that were opened, in time order
     *
     * @throws DraftingNotAllowed
     * @throws ProposalRefused when nothing was started; the message says why
     */
    public function handle(User $actor, string $week, string $seen): array
    {
        $workspace = $this->currentWorkspace->getOrFail();

        if (! $actor->exists || ! ($workspace->roleOf($actor)?->canEditContent() ?? false)) {
            throw DraftingNotAllowed::for($actor, $workspace);
        }

        $runs = DB::transaction(function () use ($actor, $workspace, $week, $seen): array {
            // One proposal at a time for a workspace, from here to the commit.
            Workspace::query()->lockForUpdate()->findOrFail($workspace->id);

            foreach ($this->checks->missingSetup($workspace) as $refusal) {
                throw $refusal;
            }

            $plan = $this->planWeek->handle($workspace, $week);

            if ($plan->isEmpty()) {
                throw ProposalRefused::nothingToDraft();
            }

            if (! hash_equals($plan->fingerprint(), $seen)) {
                throw ProposalRefused::because(
                    ProposalRefused::PLAN_CHANGED,
                    (string) __('The plan changed after you opened it. Look at it again before you start.'),
                );
            }

            foreach ($this->checks->overLimits($workspace, count($plan->posts)) as $refusal) {
                throw $refusal;
            }

            $context = $this->generators->contextFor($workspace);
            $brief = Brief::current();
            $proposal = (string) Str::uuid();
            $runs = [];

            foreach ($plan->posts as $post) {
                $runs[] = DraftRun::start($actor, $context, [
                    'proposal' => $proposal,
                    'week' => $plan->week,
                    'planned_at' => $post->at->toIso8601String(),
                    'channel_ids' => $post->channelIds(),
                    'slot_ids' => $post->slotIds,
                    'attempt' => 1,
                ], $brief, $post->pillar, $post->idea);
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
