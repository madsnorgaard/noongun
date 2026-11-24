<?php

namespace App\Domain\Content\Actions;

use App\Domain\Content\Exceptions\IllegalPostTransition;
use App\Domain\Content\Exceptions\PostActionNotAllowed;
use App\Domain\Content\Exceptions\PostNotPublishable;
use App\Domain\Content\Models\Post;
use App\Domain\Content\PostPreconditions;
use App\Domain\Content\PostState;
use App\Models\User;
use DateTimeInterface;

/**
 * A human sends a failed post again, now or at the given time. The post
 * keeps its approval because its content cannot have changed. Variants that
 * are already on their platform are never sent again.
 */
final class RetryPost
{
    public function __construct(private readonly PostPreconditions $preconditions) {}

    /**
     * @throws PostActionNotAllowed the actor is not an owner or editor of the workspace
     * @throws IllegalPostTransition the post is not failed
     * @throws PostNotPublishable a channel of a variant that is still missing cannot publish, or the time is in the past or more than a year ahead
     */
    public function handle(User $actor, Post $post, ?DateTimeInterface $at = null): Post
    {
        $this->preconditions->authorize($actor, $post);
        $this->preconditions->assertState($post, PostState::Scheduled, PostState::Failed);
        $this->preconditions->assertReadyToPublish($post);

        if ($at !== null) {
            $this->preconditions->assertSensibleTime($post, $at);
        }

        return $post->retry($at);
    }
}
