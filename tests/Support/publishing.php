<?php

use App\Domain\Channels\Models\Channel;
use App\Domain\Content\Models\Post;
use App\Domain\Content\Models\PostVariant;
use App\Domain\Content\PostState;
use App\Domain\Scheduling\PublishPostJob;
use App\Domain\Workspaces\Models\Membership;
use App\Domain\Workspaces\Models\Workspace;
use App\Domain\Workspaces\Role;
use App\Models\User;

/**
 * Helpers for the tests of the post actions, the scheduler and
 * PublishPostJob. Loaded by those test files with require_once.
 */

/**
 * A new user with the given role in the workspace.
 */
function userWithRole(Workspace $workspace, Role $role): User
{
    $user = User::factory()->create();

    Membership::factory()->role($role)->create(['workspace_id' => $workspace->id, 'user_id' => $user->id]);

    return $user;
}

/**
 * A post in the given state with one variant per channel. An int makes that
 * many variants on new channels.
 *
 * @param  int|list<Channel>  $channels
 */
function postInState(Workspace $workspace, PostState $state, int|array $channels = 1, ?DateTimeInterface $at = null): Post
{
    return inWorkspace($workspace, function () use ($state, $channels, $at): Post {
        $factory = Post::factory()->withVariants($channels);

        if ($state === PostState::Draft && $at !== null) {
            $factory = $factory->plannedFor($at);
        }

        return (match ($state) {
            PostState::Draft => $factory,
            PostState::Approved => $factory->approved(),
            PostState::Scheduled => $factory->scheduled($at),
            PostState::Publishing => $factory->publishing(),
            PostState::Published => $factory->published(),
            PostState::Failed => $factory->failed(),
            PostState::Rejected => $factory->rejected(),
        })->create();
    });
}

/**
 * A post the scheduler has claimed, and the job it would have queued.
 *
 * @param  int|list<Channel>  $channels
 * @return array{Post, PublishPostJob}
 */
function claimedPost(Workspace $workspace, int|array $channels = 1): array
{
    $post = postInState($workspace, PostState::Scheduled, $channels, now()->subMinute());

    return inWorkspace($workspace, function () use ($post): array {
        expect($post->claimForPublishing())->toBeTrue();

        return [$post, PublishPostJob::forClaimed($post)];
    });
}

/**
 * Runs the job the way a worker does, without the queue.
 */
function runPublishJob(PublishPostJob $job): void
{
    app()->call([$job, 'handle']);
}

/**
 * Reads a post again, whatever the current workspace is.
 */
function freshPost(Post $post): Post
{
    return Post::query()->withoutGlobalScopes()->findOrFail($post->id);
}

/**
 * The variants of a post in id order, whatever the current workspace is.
 *
 * @return list<PostVariant>
 */
function variantsOf(Post $post): array
{
    return array_values(PostVariant::query()
        ->withoutGlobalScopes()
        ->where('post_id', $post->id)
        ->orderBy('id')
        ->get()
        ->all());
}
