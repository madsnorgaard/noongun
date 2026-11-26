<?php

namespace App\Domain\Drafting\Actions;

use App\Domain\Content\Models\Idea;
use App\Domain\Drafting\Exceptions\DraftingNotAllowed;
use App\Domain\Drafting\Exceptions\ProposalRefused;
use App\Domain\Drafting\Models\DraftRun;
use App\Domain\Drafting\Planning\PlanIdeaDraft;
use App\Domain\Drafting\Planning\ProposalChecks;
use App\Domain\Drafting\Planning\RunToOpen;
use App\Domain\Workspaces\Models\Workspace;
use App\Models\User;
use DateTimeInterface;

/**
 * "Draft a post" on one idea: one pending draft run for one post with a
 * text for each chosen channel, and one queued job. It calls no provider
 * itself, and what comes out is a draft that a person approves or not.
 *
 * It starts through StartDraftRuns, as a proposed week does, so everything
 * that refuses a week refuses here, the run counts towards the same limits,
 * and it is retried, recovered and stopped by the same code. The run is
 * told apart by request['source'] = 'idea'. It names no week and no slot.
 *
 * The idea, the channels and the pillar are read inside the current
 * workspace, under the lock. The pillar is the one of the idea and is never
 * taken from the request.
 */
final class DraftFromIdea
{
    public function __construct(
        private readonly PlanIdeaDraft $planIdeaDraft,
        private readonly ProposalChecks $checks,
        private readonly StartDraftRuns $start,
    ) {}

    /**
     * @param  User  $actor  the human who asked; an owner or editor of the workspace
     * @param  list<int>  $channelIds  the channels to write for
     * @param  DateTimeInterface|null  $plannedAt  an instant, or null to decide after approval
     * @param  int  $ideaId  an idea of the current workspace; any other is a ModelNotFoundException
     * @param  string  $seen  IdeaDraftPlan::fingerprint() of what the person was shown
     *
     * @throws DraftingNotAllowed
     * @throws ProposalRefused when nothing was started; the message says why
     */
    public function handle(User $actor, int $ideaId, array $channelIds, ?DateTimeInterface $plannedAt, string $seen): DraftRun
    {
        $runs = $this->start->handle($actor, false, function (Workspace $workspace) use ($ideaId, $channelIds, $plannedAt, $seen): array {
            $plan = $this->planIdeaDraft->handle(Idea::query()->findOrFail($ideaId), $channelIds, $plannedAt);

            if ($plan->channelProblems !== []) {
                throw ProposalRefused::because(ProposalRefused::CHANNELS_CHOSEN, implode(' ', $plan->channelProblems));
            }

            if ($plan->timeProblems !== []) {
                throw ProposalRefused::because(ProposalRefused::PLANNED_TIME, implode(' ', $plan->timeProblems));
            }

            if (! hash_equals($plan->fingerprint(), $seen)) {
                // A second press finds the run of the first, which also
                // changed the fingerprint. "Still being drafted" or a
                // limit says more than "this changed".
                foreach ($this->checks->overLimits($workspace, 1) as $refusal) {
                    throw $refusal;
                }

                throw ProposalRefused::because(
                    ProposalRefused::PLAN_CHANGED,
                    (string) __('This changed after you opened it: the choice is another one, or a post was drafted from this idea in the meantime. Look at it again before you start.'),
                );
            }

            return [new RunToOpen([
                'source' => DraftRun::SOURCE_IDEA,
                'planned_at' => $plan->plannedAt?->toIso8601String(),
                'channel_ids' => $plan->channelIds(),
            ], $plan->pillar, $plan->idea)];
        });

        return $runs[0];
    }
}
