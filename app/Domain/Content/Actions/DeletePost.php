<?php

namespace App\Domain\Content\Actions;

use App\Domain\Content\Exceptions\PostActionNotAllowed;
use App\Domain\Content\Exceptions\PostNotDeletable;
use App\Domain\Content\Models\Post;
use App\Domain\Content\Models\PostVariant;
use App\Domain\Content\PostPreconditions;
use App\Domain\Content\PostState;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * Deletes a post that never left the building: a draft or a rejected post
 * with no variant on a platform. Deleting a row cascades to its variants, so
 * anything approved or later is kept: it is, or may at any moment become, the
 * only record of something public. Deleting here never deletes anything from
 * a platform.
 *
 * A draft can hold a published variant when a partly published post was
 * returned to draft. Such a post is kept as well. So is a post with a variant
 * whose outcome is unknown (PostVariant::hasUnknownOutcome()): the platform
 * was called and may hold the post, and this row is the only trace of it.
 */
final class DeletePost
{
    public function __construct(private readonly PostPreconditions $preconditions) {}

    /**
     * Whether the rule allows deleting the post as it is now.
     */
    public static function allows(Post $post): bool
    {
        return in_array($post->state, [PostState::Draft, PostState::Rejected], true)
            && ! $post->variants->contains(fn (PostVariant $variant): bool => $variant->isPublished() || $variant->hasUnknownOutcome());
    }

    /**
     * @throws PostActionNotAllowed the actor is not an owner or editor of the workspace
     * @throws PostNotDeletable the post is approved or later, or has a variant that is or may be published
     * @throws ModelNotFoundException the post is gone
     */
    public function handle(User $actor, Post $post): void
    {
        $this->preconditions->authorize($actor, $post);

        DB::transaction(function () use ($post): void {
            // The row lock keeps an approval from landing between the check
            // and the delete.
            $locked = Post::query()->lockForUpdate()->findOrFail($post->id);

            if (! self::allows($locked)) {
                throw PostNotDeletable::for($locked);
            }

            $locked->delete();
        });
    }
}
