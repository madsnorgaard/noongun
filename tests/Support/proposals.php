<?php

use App\Domain\Drafting\Actions\ProposeWeek;
use App\Domain\Drafting\GenerateDraftJob;
use App\Domain\Drafting\Models\DraftRun;
use App\Domain\Drafting\Planning\PlanWeek;
use App\Domain\Drafting\Planning\WeekPlan;
use App\Domain\Workspaces\Models\Workspace;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/**
 * Helpers for the tests of "Propose a week": the plan, the action, the job
 * and the pages. Loaded by those test files with require_once.
 */

/**
 * The plan for a week of the workspace, as the page and the action see it.
 *
 * @param  string  $week  a Monday on the workspace calendar
 */
function planFor(Workspace $workspace, string $week): WeekPlan
{
    return inWorkspace($workspace, fn () => app(PlanWeek::class)->handle($workspace, $week));
}

/**
 * Starts a proposal the way the controller does, with the fingerprint of
 * the plan as it is now unless another one is given.
 *
 * @return list<DraftRun>
 */
function proposeWeek(Workspace $workspace, User $actor, string $week = '2026-11-30', ?string $seen = null): array
{
    return inWorkspace($workspace, fn () => app(ProposeWeek::class)->handle(
        $actor,
        $week,
        $seen ?? app(PlanWeek::class)->handle($workspace, $week)->fingerprint(),
    ));
}

/**
 * Runs the job the way a worker does, without the queue.
 */
function runDraftJob(GenerateDraftJob $job): void
{
    app()->call([$job, 'handle']);
}

/**
 * Runs every drafting job that Queue::fake() holds, and the ones those
 * queue in turn, until none is new. Jobs that ran in an earlier call are
 * run again, which a draft run must survive. Returns how many jobs the
 * queue held in the end.
 */
function runQueuedDraftJobs(): int
{
    $ran = 0;

    do {
        $jobs = Queue::pushed(GenerateDraftJob::class);

        foreach ($jobs->slice($ran) as $job) {
            runDraftJob($job);
            $ran++;
        }
    } while (Queue::pushed(GenerateDraftJob::class)->count() > $ran);

    foreach (Queue::pushed(GenerateDraftJob::class) as $job) {
        runDraftJob($job);
    }

    return $ran;
}

/**
 * The stored row of a draft run, read without a workspace.
 */
function freshRun(DraftRun|int $run): object
{
    return DB::table('draft_runs')->where('id', $run instanceof DraftRun ? $run->id : $run)->firstOrFail();
}
