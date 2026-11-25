<?php

use App\Domain\Workspaces\Models\Workspace;
use App\Domain\Workspaces\Role;
use App\Models\User;

require_once __DIR__.'/../../Support/members.php';

test('the only owner of a workspace cannot delete their account', function () {
    $workspace = Workspace::factory()->create(['name' => 'Signal Hill']);
    $owner = workspaceMember($workspace, Role::Owner);
    workspaceMember($workspace, Role::Editor);

    $this->actingAs($owner)
        ->from(route('profile.edit'))
        ->delete(route('profile.destroy'), ['password' => 'password'])
        ->assertRedirect(route('profile.edit'))
        ->assertSessionHasErrors([
            'account' => 'You are the only owner of Signal Hill. A workspace must keep an owner, so this account cannot be deleted.',
        ]);

    $this->assertAuthenticatedAs($owner);
    expect($owner->fresh())->not->toBeNull()
        ->and($workspace->roleOf($owner))->toBe(Role::Owner);

    $this->get(route('setup.create'))->assertNotFound();
});

test('an owner can delete their account when the workspace has another owner', function () {
    $workspace = Workspace::factory()->create();
    $owner = workspaceMember($workspace, Role::Owner);
    $other = workspaceMember($workspace, Role::Owner);

    $this->actingAs($owner)
        ->delete(route('profile.destroy'), ['password' => 'password'])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('home'));

    $this->assertGuest();
    expect($owner->fresh())->toBeNull()
        ->and($workspace->roleOf($other))->toBe(Role::Owner);
});

test('the only owner of one workspace cannot delete their account, whatever they are elsewhere', function () {
    $shared = Workspace::factory()->create();
    $owner = workspaceMember($shared, Role::Owner);
    workspaceMember($shared, Role::Owner);
    $alone = Workspace::factory()->create();
    $alone->memberships()->create(['user_id' => $owner->id, 'role' => Role::Owner]);

    $this->actingAs($owner)
        ->delete(route('profile.destroy'), ['password' => 'password'])
        ->assertSessionHasErrors('account');

    expect($owner->fresh())->not->toBeNull();
});

test('an editor can delete their account', function () {
    $workspace = Workspace::factory()->create();
    workspaceMember($workspace, Role::Owner);
    $editor = workspaceMember($workspace, Role::Editor);

    $this->actingAs($editor)
        ->delete(route('profile.destroy'), ['password' => 'password'])
        ->assertSessionHasNoErrors();

    expect($editor->fresh())->toBeNull();
});

test('profile page is displayed', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->get(route('profile.edit'));

    $response->assertOk();
});

test('profile information can be updated', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    $user->refresh();

    expect($user->name)->toBe('Test User');
    expect($user->email)->toBe('test@example.com');
    expect($user->email_verified_at)->toBeNull();
});

test('email is stored in lowercase so the user can still log in', function () {
    $user = User::factory()->create();

    $this
        ->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => 'Test User',
            'email' => 'Test@Example.com',
        ])
        ->assertSessionHasNoErrors();

    expect($user->refresh()->email)->toBe('test@example.com');

    $this->post(route('logout'));

    $this->post(route('login.store'), [
        'email' => 'Test@Example.com',
        'password' => 'password',
    ]);

    $this->assertAuthenticatedAs($user);
});

test('email must be unique regardless of case', function () {
    User::factory()->create(['email' => 'taken@example.com']);

    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->from(route('profile.edit'))
        ->patch(route('profile.update'), [
            'name' => 'Test User',
            'email' => 'Taken@Example.com',
        ]);

    $response
        ->assertSessionHasErrors('email')
        ->assertRedirect(route('profile.edit'));
});

test('email verification status is unchanged when the email address is unchanged', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => 'Test User',
            'email' => $user->email,
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    expect($user->refresh()->email_verified_at)->not->toBeNull();
});

test('user can delete their account', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->delete(route('profile.destroy'), [
            'password' => 'password',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('home'));

    $this->assertGuest();
    expect($user->fresh())->toBeNull();
});

test('correct password must be provided to delete account', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->from(route('profile.edit'))
        ->delete(route('profile.destroy'), [
            'password' => 'wrong-password',
        ]);

    $response
        ->assertSessionHasErrors('password')
        ->assertRedirect(route('profile.edit'));

    expect($user->fresh())->not->toBeNull();
});
