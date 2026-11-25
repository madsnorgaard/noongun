<?php

namespace App\Domain\Workspaces\Policies;

use App\Domain\Workspaces\CurrentWorkspace;
use App\Domain\Workspaces\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * The plain rule for content a workspace owns, used by the models that need
 * nothing more: the brief, pillars, ideas, slots and draft runs. Every
 * member may look. Owners and editors may create, change and delete, and
 * only records of the current workspace.
 */
class ContentPolicy
{
    public function __construct(private readonly CurrentWorkspace $current) {}

    public function viewAny(User $user): bool
    {
        return $this->roleInCurrentWorkspace($user)?->canView() ?? false;
    }

    public function view(User $user, Model $record): bool
    {
        return $this->roleFor($user, $record)?->canView() ?? false;
    }

    public function create(User $user): bool
    {
        return $this->roleInCurrentWorkspace($user)?->canEditContent() ?? false;
    }

    public function update(User $user, Model $record): bool
    {
        return $this->roleFor($user, $record)?->canEditContent() ?? false;
    }

    public function delete(User $user, Model $record): bool
    {
        return $this->roleFor($user, $record)?->canEditContent() ?? false;
    }

    private function roleFor(User $user, Model $record): ?Role
    {
        $workspaceId = $record->getAttribute('workspace_id');

        if (! is_numeric($workspaceId) || (int) $workspaceId !== $this->current->id()) {
            return null;
        }

        return $this->roleInCurrentWorkspace($user);
    }

    private function roleInCurrentWorkspace(User $user): ?Role
    {
        return $this->current->get()?->roleOf($user);
    }
}
