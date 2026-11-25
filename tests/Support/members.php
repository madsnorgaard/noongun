<?php

use App\Domain\Workspaces\Models\Membership;
use App\Domain\Workspaces\Models\Workspace;
use App\Domain\Workspaces\Role;
use App\Models\User;

/**
 * A new user with the given role in the workspace.
 */
function workspaceMember(Workspace $workspace, Role $role = Role::Owner): User
{
    return Membership::factory()->for($workspace)->role($role)->create()->user;
}
