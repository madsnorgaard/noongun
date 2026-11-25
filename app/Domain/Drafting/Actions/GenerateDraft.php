<?php

namespace App\Domain\Drafting\Actions;

use App\Domain\Content\Models\Post;
use App\Domain\Drafting\CostEstimator;
use App\Domain\Drafting\DraftGenerators;
use App\Domain\Drafting\DraftRequest;
use App\Domain\Drafting\Exceptions\DraftGeneratorNotAvailable;
use App\Domain\Drafting\Exceptions\DraftingContextChanged;
use App\Domain\Drafting\Exceptions\DraftingException;
use App\Domain\Drafting\Exceptions\DraftingNotAllowed;
use App\Domain\Drafting\Models\DraftRun;
use App\Domain\Workspaces\CurrentWorkspace;
use App\Domain\Workspaces\Models\Workspace;
use App\Models\User;
use Closure;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Runs one opened draft run to its end: asks the workspace's generator for
 * the draft, and either creates the draft post and marks the run succeeded,
 * or marks the run failed with a reason and what it used. A run never stays
 * pending or running after this returns or throws.
 *
 * Open the run first with DraftRun::start(), in the request that the human
 * made, so the run is visible while it waits. This action can then be
 * called at once or from a queued job, with the current workspace set.
 *
 * Nothing is asked of a provider, and so nothing is spent, unless the actor
 * may write posts in the workspace and the provider, model, prompt version
 * and payer that resolve now are the ones the run was opened with.
 *
 * Returns null, and does nothing, when the run was already started: a job
 * that is delivered twice generates once.
 */
final class GenerateDraft
{
    public function __construct(
        private readonly DraftGenerators $generators,
        private readonly CreateDraftFromResult $createDraft,
        private readonly CostEstimator $costs,
        private readonly CurrentWorkspace $currentWorkspace,
    ) {}

    /**
     * @param  User  $actor  the human who asked; an owner or editor of the workspace
     * @param  (Closure(DraftRun, DraftingException): void)|null  $afterFailure  called once the run is marked failed for a DraftingException, in the same transaction and under the lock on the workspace row; a job opens the run for a retry here
     *
     * @throws DraftingException after the run was marked failed; its message is the stored reason
     * @throws DraftGeneratorNotAvailable after the run was marked failed
     * @throws DraftingNotAllowed after the run was marked failed, and before any provider was called
     */
    public function handle(User $actor, DraftRun $run, DraftRequest $request, ?DateTimeInterface $plannedAt = null, ?Closure $afterFailure = null): ?Post
    {
        if (! $run->markRunning()) {
            return null;
        }

        try {
            $workspace = $this->currentWorkspace->getOrFail();

            if (! $actor->exists || ! ($workspace->roleOf($actor)?->canEditContent() ?? false)) {
                throw DraftingNotAllowed::for($actor, $workspace);
            }

            $context = $this->generators->contextFor($workspace);

            // The run says which model was asked for and whose key pays.
            // If that is no longer what resolves, nothing is spent.
            if (($changed = DraftingContextChanged::between($run, $context)) !== null) {
                throw $changed;
            }

            $result = $this->generators->for($context)->generate($request, $context);

            return $this->createDraft->handle($actor, $run, $result, $plannedAt);
        } catch (DraftingException $failure) {
            $this->settle($run, $failure, $afterFailure);

            throw $failure;
        } catch (Throwable $failure) {
            $run->markFailed($failure instanceof DraftGeneratorNotAvailable || $failure instanceof DraftingNotAllowed
                ? $failure->getMessage()
                : 'The draft could not be generated because of an unexpected error.');

            throw $failure;
        }
    }

    /**
     * Marks the run failed and lets the caller react, as one step for
     * everyone else: under the lock on the workspace row that ProposeWeek
     * takes, in one transaction. A run for a retry that $afterFailure opens
     * therefore exists the moment the failed run stops being open, and no
     * proposal can start in between.
     *
     * @param  (Closure(DraftRun, DraftingException): void)|null  $afterFailure
     */
    private function settle(DraftRun $run, DraftingException $failure, ?Closure $afterFailure): void
    {
        $cost = $failure->usage !== null ? $this->costs->estimate($failure->usage, $run->model) : null;

        try {
            DB::transaction(function () use ($run, $failure, $cost, $afterFailure): void {
                Workspace::query()->lockForUpdate()->findOrFail($run->workspace_id);

                if ($run->markFailed($failure->getMessage(), $failure->usage, $cost) && $afterFailure !== null) {
                    $afterFailure($run, $failure);
                }
            });
        } catch (Throwable $unexpected) {
            // Whatever went wrong in the reaction, the run does not stay open.
            $run->markFailed($failure->getMessage(), $failure->usage, $cost);

            report($unexpected);
        }
    }
}
