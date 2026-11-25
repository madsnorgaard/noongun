<?php

namespace App\Domain\Drafting\Policies;

use App\Domain\Drafting\Models\LlmCredential;
use App\Domain\Workspaces\CurrentWorkspace;
use App\Models\User;

/**
 * A workspace's own LLM keys are for its owners only: seeing that one is
 * set, setting it and removing it. Editors and viewers get nothing.
 */
class LlmCredentialPolicy
{
    public function __construct(private readonly CurrentWorkspace $current) {}

    public function viewAny(User $user): bool
    {
        return $this->managesCurrentWorkspace($user);
    }

    public function create(User $user): bool
    {
        return $this->managesCurrentWorkspace($user);
    }

    public function update(User $user, LlmCredential $credential): bool
    {
        return $credential->workspace_id === $this->current->id() && $this->managesCurrentWorkspace($user);
    }

    public function delete(User $user, LlmCredential $credential): bool
    {
        return $this->update($user, $credential);
    }

    private function managesCurrentWorkspace(User $user): bool
    {
        return $this->current->get()?->roleOf($user)?->canManageWorkspace() ?? false;
    }
}
