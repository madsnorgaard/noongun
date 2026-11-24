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
 * Commits an approved post to a time, or moves a scheduled post to another
 * time. The time is an instant: convert the user's wall-clock time with
 * Workspace::toUtc() first. Pass now() to publish at the next minute.
 */
final class SchedulePost
{
    public function __construct(private readonly PostPreconditions $preconditions) {}

    /**
     * @throws PostActionNotAllowed the actor is not an owner or editor of the workspace
     * @throws IllegalPostTransition the post is neither approved nor scheduled
     * @throws PostNotPublishable a channel can no longer publish, or the time is in the past or more than a year ahead
     */
    public function handle(User $actor, Post $post, DateTimeInterface $at): Post
    {
        $this->preconditions->authorize($actor, $post);
        $this->preconditions->assertState($post, PostState::Scheduled, PostState::Approved, PostState::Scheduled);
        $this->preconditions->assertReadyToPublish($post);
        $this->preconditions->assertSensibleTime($post, $at);

        return $post->schedule($at);
    }
}
