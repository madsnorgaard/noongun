<?php

use App\Domain\Workspaces\Role;

it('maps each role to its abilities', function (Role $role, bool $manage, bool $edit) {
    expect($role->canManageWorkspace())->toBe($manage)
        ->and($role->canEditContent())->toBe($edit)
        ->and($role->canView())->toBeTrue();
})->with([
    'owner' => [Role::Owner, true, true],
    'editor' => [Role::Editor, false, true],
    'viewer' => [Role::Viewer, false, false],
]);
