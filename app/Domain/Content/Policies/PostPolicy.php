<?php

namespace App\Domain\Content\Policies;

use App\Domain\Content\Models\Post;
use App\Domain\Workspaces\CurrentWorkspace;
use App\Domain\Workspaces\Role;
use App\Models\User;

/**
 * Every member may look at the posts of a workspace. Owners and editors may
 * write, approve, schedule and delete them. Whether a post is in a state
 * that allows the change is decided by the post actions, not here.
 */
class PostPolicy
{
    public function __construct(private readonly CurrentWorkspace $current) {}

    public function viewAny(User $user): bool
    {
        return $this->roleInCurrentWorkspace($user)?->canView() ?? false;
    }

    public function view(User $user, Post $post): bool
    {
        return $post->workspace?->roleOf($user)?->canView() ?? false;
    }

    public function create(User $user): bool
    {
        return $this->roleInCurrentWorkspace($user)?->canEditContent() ?? false;
    }

    public function update(User $user, Post $post): bool
    {
        return $this->mayChange($user, $post);
    }

    public function delete(User $user, Post $post): bool
    {
        return $this->mayChange($user, $post);
    }

    public function approve(User $user, Post $post): bool
    {
        return $this->mayChange($user, $post);
    }

    public function reject(User $user, Post $post): bool
    {
        return $this->mayChange($user, $post);
    }

    public function schedule(User $user, Post $post): bool
    {
        return $this->mayChange($user, $post);
    }

    public function unschedule(User $user, Post $post): bool
    {
        return $this->mayChange($user, $post);
    }

    public function returnToDraft(User $user, Post $post): bool
    {
        return $this->mayChange($user, $post);
    }

    public function retry(User $user, Post $post): bool
    {
        return $this->mayChange($user, $post);
    }

    private function mayChange(User $user, Post $post): bool
    {
        return $post->workspace?->roleOf($user)?->canEditContent() ?? false;
    }

    private function roleInCurrentWorkspace(User $user): ?Role
    {
        return $this->current->get()?->roleOf($user);
    }
}
