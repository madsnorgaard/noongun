<?php

namespace App\Domain\Content\Actions;

use App\Domain\Content\Exceptions\IllegalPostTransition;
use App\Domain\Content\Exceptions\PostActionNotAllowed;
use App\Domain\Content\Models\Post;
use App\Domain\Content\PostPreconditions;
use App\Models\User;

/**
 * A human turns a draft down. The post is kept and can be reopened with
 * ReturnPostToDraft.
 */
final class RejectPost
{
    public function __construct(private readonly PostPreconditions $preconditions) {}

    /**
     * @throws PostActionNotAllowed the actor is not an owner or editor of the workspace
     * @throws IllegalPostTransition the post is not a draft
     */
    public function handle(User $actor, Post $post): Post
    {
        $this->preconditions->authorize($actor, $post);

        return $post->reject();
    }
}
