<?php

namespace App\Domain\Workspaces\Policies;

use App\Domain\Workspaces\Models\Workspace;
use App\Models\User;

class WorkspacePolicy
{
    public function view(User $user, Workspace $workspace): bool
    {
        return $workspace->roleOf($user)?->canView() ?? false;
    }

    public function update(User $user, Workspace $workspace): bool
    {
        return $workspace->roleOf($user)?->canManageWorkspace() ?? false;
    }

    public function manageMembers(User $user, Workspace $workspace): bool
    {
        return $workspace->roleOf($user)?->canManageWorkspace() ?? false;
    }

    public function editContent(User $user, Workspace $workspace): bool
    {
        return $workspace->roleOf($user)?->canEditContent() ?? false;
    }

    public function delete(User $user, Workspace $workspace): bool
    {
        return $workspace->roleOf($user)?->canManageWorkspace() ?? false;
    }
}
