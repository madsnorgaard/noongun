<?php

namespace App\Domain\Content\Actions;

use App\Domain\Content\Exceptions\IllegalPostTransition;
use App\Domain\Content\Exceptions\PostActionNotAllowed;
use App\Domain\Content\Exceptions\PostNotPublishable;
use App\Domain\Content\Models\Post;
use App\Domain\Content\PostPreconditions;
use App\Domain\Content\PostState;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * A human signs off a draft. This is the only caller of Post::approve() in
 * the application, and it must only ever be called from a request made by
 * the signed-in user: never from a job, a command or a default
 * (tests/Feature/Scheduling/NothingPublishesWithoutApprovalTest.php).
 *
 * A draft that already has a planned time is scheduled in the same step
 * (ADR 0009), so the result is either approved or scheduled.
 */
final class ApprovePost
{
    public function __construct(private readonly PostPreconditions $preconditions) {}

    /**
     * @throws PostActionNotAllowed the actor is not an owner or editor of the workspace
     * @throws IllegalPostTransition the post is not a draft
     * @throws PostNotPublishable no variants, a channel that cannot publish, a text that does not fit, or a planned time that has passed
     */
    public function handle(User $actor, Post $post): Post
    {
        $this->preconditions->authorize($actor, $post);
        $this->preconditions->assertState($post, PostState::Approved, PostState::Draft);
        $this->preconditions->assertReadyToPublish($post);

        try {
            return DB::transaction(function () use ($actor, $post): Post {
                // approve() reloads the row, so the planned time checked
                // here is the one that will be scheduled.
                $post->approve($actor);

                if ($post->scheduled_at === null) {
                    return $post;
                }

                $this->preconditions->assertSensibleTime($post, $post->scheduled_at);

                return $post->schedule();
            });
        } catch (PostNotPublishable $e) {
            // The approval was rolled back with the transaction.
            $post->refresh();

            throw $e;
        }
    }
}
