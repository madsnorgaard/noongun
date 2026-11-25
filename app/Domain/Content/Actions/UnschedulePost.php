<?php

namespace App\Domain\Content\Actions;

use App\Domain\Content\Exceptions\IllegalPostTransition;
use App\Domain\Content\Exceptions\PostActionNotAllowed;
use App\Domain\Content\Models\Post;
use App\Domain\Content\PostPreconditions;
use App\Models\User;

/**
 * Takes a scheduled post out of the queue. It stays approved and keeps its
 * time as a plan.
 */
final class UnschedulePost
{
    public function __construct(private readonly PostPreconditions $preconditions) {}

    /**
     * @throws PostActionNotAllowed the actor is not an owner or editor of the workspace
     * @throws IllegalPostTransition the post is not scheduled, for example because the scheduler has just claimed it
     */
    public function handle(User $actor, Post $post): Post
    {
        $this->preconditions->authorize($actor, $post);

        return $post->unschedule();
    }
}
