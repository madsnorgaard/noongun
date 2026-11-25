<?php

namespace App\Domain\Drafting\Actions;

use App\Domain\Content\Actions\SaveDraft;
use App\Domain\Content\Exceptions\PostActionNotAllowed;
use App\Domain\Content\Models\Post;
use App\Domain\Drafting\CostEstimator;
use App\Domain\Drafting\DraftResult;
use App\Domain\Drafting\Exceptions\DraftingStateConflict;
use App\Domain\Drafting\Models\DraftRun;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

/**
 * The only way a generated result becomes a post, and the post it makes is
 * a draft. It is written through SaveDraft, the same path as a post typed
 * by hand, so it has no approval and is not in the publishing queue: a
 * human reads it and approves it, or it goes nowhere (project rule 1).
 *
 * Recording the result on the run and creating the post happen in one
 * transaction. The run moves from running to succeeded with one conditional
 * UPDATE, so a result that is delivered twice creates one post.
 *
 * $plannedAt is a proposal, for example from Slot::nextOccurrence(). It is
 * stored as the draft's planned time and schedules nothing.
 */
final class CreateDraftFromResult
{
    public function __construct(
        private readonly SaveDraft $saveDraft,
        private readonly CostEstimator $costs,
    ) {}

    /**
     * @param  User  $actor  the human who asked for the draft; an owner or editor of the workspace
     *
     * @throws PostActionNotAllowed the actor may not write posts in the workspace
     * @throws DraftingStateConflict the run is not running: it was settled before, or never started
     */
    public function handle(User $actor, DraftRun $run, DraftResult $result, ?DateTimeInterface $plannedAt = null): Post
    {
        return DB::transaction(function () use ($actor, $run, $result, $plannedAt): Post {
            if (! $run->markSucceeded($result, $this->costs->estimate($result->usage, $run->model))) {
                throw DraftingStateConflict::notRunning($run);
            }

            return $this->saveDraft
                ->handle($actor, null, $plannedAt, $result->variants())
                ->attributeTo($run);
        });
    }
}
