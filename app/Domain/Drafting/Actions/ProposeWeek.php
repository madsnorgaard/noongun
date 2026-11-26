<?php

namespace App\Domain\Drafting\Actions;

use App\Domain\Drafting\Exceptions\DraftingNotAllowed;
use App\Domain\Drafting\Exceptions\ProposalRefused;
use App\Domain\Drafting\Models\DraftRun;
use App\Domain\Drafting\Planning\PlannedPost;
use App\Domain\Drafting\Planning\PlanWeek;
use App\Domain\Drafting\Planning\RunToOpen;
use App\Domain\Workspaces\Models\Workspace;
use App\Models\User;

/**
 * Starts the drafting of one week: one pending draft run for each post of
 * the plan, and one queued job for each run. It calls no provider itself;
 * GenerateDraftJob does, one run at a time, and what comes out is a draft
 * that a person approves or not.
 *
 * The checks, the limits, the lock on the workspace row and the queueing
 * are those of StartDraftRuns. What is added here is the plan of the week
 * and its fingerprint: the plan is worked out again under the lock and the
 * proposal is refused when it is not the one the person was shown.
 *
 * The runs of one proposal share request['proposal']. Each also notes its
 * week, its planned time, its channels, its slots and its attempt.
 */
final class ProposeWeek
{
    public const NOT_QUEUED = StartDraftRuns::NOT_QUEUED;

    public function __construct(
        private readonly PlanWeek $planWeek,
        private readonly StartDraftRuns $start,
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
        return $this->start->handle($actor, true, function (Workspace $workspace) use ($week, $seen): array {
            $plan = $this->planWeek->handle($workspace, $week);

            if ($plan->posts === []) {
                throw ProposalRefused::nothingToDraft();
            }

            if (! hash_equals($plan->fingerprint(), $seen)) {
                throw ProposalRefused::because(
                    ProposalRefused::PLAN_CHANGED,
                    (string) __('The plan changed after you opened it. Look at it again before you start.'),
                );
            }

            return array_map(fn (PlannedPost $post): RunToOpen => new RunToOpen([
                'week' => $plan->week,
                'planned_at' => $post->at->toIso8601String(),
                'channel_ids' => $post->channelIds(),
                'slot_ids' => $post->slotIds,
            ], $post->pillar, $post->idea), $plan->posts);
        });
    }
}
