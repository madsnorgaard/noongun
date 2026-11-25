<?php

namespace App\Domain\Content\Actions;

use App\Domain\Content\Exceptions\IllegalPostTransition;
use App\Domain\Content\Exceptions\PostActionNotAllowed;
use App\Domain\Content\Models\Post;
use App\Domain\Content\PostPreconditions;
use App\Models\User;

/**
 * Opens an approved, scheduled, failed or rejected post for editing. The
 * approval is erased, so the post must be approved again before it can be
 * published. Variants that are already on their platform stay as they are.
 */
final class ReturnPostToDraft
{
    public function __construct(private readonly PostPreconditions $preconditions) {}

    /**
     * @throws PostActionNotAllowed the actor is not an owner or editor of the workspace
     * @throws IllegalPostTransition the post is a draft, is being published or is published
     */
    public function handle(User $actor, Post $post): Post
    {
        $this->preconditions->authorize($actor, $post);

        return $post->returnToDraft();
    }
}
