<?php

use App\Domain\Workspaces\Models\Workspace;
use App\Domain\Workspaces\Role;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

function setupInput(array $overrides = []): array
{
    return [
        'name' => 'Owner',
        'email' => 'Owner@Example.com',
        'password' => 'a long enough password',
        'password_confirmation' => 'a long enough password',
        'workspace_name' => 'My workspace',
        'timezone' => 'Africa/Johannesburg',
        'locale' => 'en',
        ...$overrides,
    ];
}

it('sends every page to the setup screen on a fresh instance', function (string $path) {
    $this->get($path)->assertRedirect(route('setup.create'));
})->with(['/', '/login', '/dashboard', '/forgot-password', '/settings/profile']);

it('does not redirect the health check', function () {
    $this->get('/up')->assertOk();
});

it('shows the setup screen with timezone choices', function () {
    $this->get(route('setup.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('setup/FirstRun')
            ->where('defaultTimezone', 'Europe/Copenhagen')
            ->where('timezones', fn ($zones) => collect($zones)->contains('Europe/Copenhagen')
                && collect($zones)->contains('Africa/Johannesburg')));
});

it('creates the owner and the workspace and logs in', function () {
    $this->post(route('setup.store'), setupInput())
        ->assertRedirect(route('dashboard'));

    $user = User::sole();
    $workspace = Workspace::sole();

    $this->assertAuthenticatedAs($user);
    expect($user->email)->toBe('owner@example.com')
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($workspace->name)->toBe('My workspace')
        ->and($workspace->slug)->toBe('my-workspace')
        ->and($workspace->timezone)->toBe('Africa/Johannesburg')
        ->and($workspace->roleOf($user))->toBe(Role::Owner);

    $this->get(route('dashboard'))->assertOk();
});

it('validates the input', function () {
    $this->post(route('setup.store'), setupInput(['timezone' => 'Mars/Olympus', 'locale' => 'xx', 'password_confirmation' => 'nope']))
        ->assertSessionHasErrors(['timezone', 'locale', 'password']);

    expect(User::count())->toBe(0);
});

it('closes the setup screen once an owner exists', function () {
    User::factory()->create();

    $this->get(route('setup.create'))->assertNotFound();
    $this->post(route('setup.store'), setupInput())->assertNotFound();
    expect(User::count())->toBe(1);
});

it('keeps registration closed in self-hosted mode', function () {
    User::factory()->create();

    $this->get('/register')->assertNotFound();
    $this->post('/register', [
        'name' => 'Stranger',
        'email' => 'stranger@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertNotFound();
});
