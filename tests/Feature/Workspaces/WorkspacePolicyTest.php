<?php

use App\Domain\Workspaces\Models\Membership;
use App\Domain\Workspaces\Models\Workspace;
use App\Domain\Workspaces\Role;
use App\Models\User;

function memberOf(Workspace $workspace, Role $role): User
{
    return Membership::factory()->for($workspace)->role($role)->create()->user;
}

it('lets each role do what it should', function (Role $role, bool $view, bool $edit, bool $manage) {
    $workspace = Workspace::factory()->create();
    $user = memberOf($workspace, $role);

    expect($user->can('view', $workspace))->toBe($view)
        ->and($user->can('editContent', $workspace))->toBe($edit)
        ->and($user->can('update', $workspace))->toBe($manage)
        ->and($user->can('manageMembers', $workspace))->toBe($manage)
        ->and($user->can('delete', $workspace))->toBe($manage);
})->with([
    'owner' => [Role::Owner, true, true, true],
    'editor' => [Role::Editor, true, true, false],
    'viewer' => [Role::Viewer, true, false, false],
]);

it('gives an owner of one workspace no rights in another', function () {
    $mine = Workspace::factory()->create();
    $theirs = Workspace::factory()->create();
    $user = memberOf($mine, Role::Owner);

    expect($user->can('view', $theirs))->toBeFalse()
        ->and($user->can('update', $theirs))->toBeFalse();
});
