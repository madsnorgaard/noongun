<?php

namespace App\Domain\Drafting\Exceptions;

use App\Domain\Workspaces\Models\Workspace;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * The user is not an owner or editor of the workspace. Laravel renders an
 * AuthorizationException as a 403 response without further code.
 */
class DraftingNotAllowed extends AuthorizationException
{
    public static function for(User $user, Workspace $workspace): self
    {
        return new self("User #{$user->getKey()} may not write the brief or request drafts in workspace #{$workspace->id}.");
    }
}
