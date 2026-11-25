<?php

use App\Domain\Workspaces\Models\Workspace;
use App\Domain\Workspaces\Role;
use App\Models\User;

require_once __DIR__.'/../Support/members.php';

beforeEach(function () {
    $this->first = Workspace::factory()->create();
    $this->owner = workspaceMember($this->first, Role::Owner);
});

it('refuses guests', function () {
    $this->get('/horizon')->assertForbidden();
});

describe('with operators listed', function () {
    beforeEach(function () {
        config()->set('noongun.operators', ['operator@example.com']);
    });

    it('lets a listed operator in, whatever the case of the address', function () {
        $operator = User::factory()->create(['email' => 'Operator@Example.com']);

        $this->actingAs($operator)->get('/horizon')->assertOk();
    });

    it('refuses a listed operator whose address is not verified', function () {
        $operator = User::factory()->unverified()->create(['email' => 'operator@example.com']);

        $this->actingAs($operator)->get('/horizon')->assertForbidden();
    });

    it('refuses workspace owners who are not listed', function (string $mode) {
        config()->set('noongun.mode', $mode);

        $this->actingAs($this->owner)->get('/horizon')->assertForbidden();
    })->with(['self_hosted', 'saas']);
});

describe('with no operators listed', function () {
    beforeEach(function () {
        config()->set('noongun.operators', []);
    });

    it('lets the owner of the first workspace in on a self-hosted instance', function () {
        $this->actingAs($this->owner)->get('/horizon')->assertOk();
    });

    it('refuses everyone else on a self-hosted instance', function () {
        $editor = workspaceMember($this->first, Role::Editor);
        $laterOwner = workspaceMember(Workspace::factory()->create(), Role::Owner);

        $this->actingAs($editor)->get('/horizon')->assertForbidden();
        $this->actingAs($laterOwner)->get('/horizon')->assertForbidden();
        $this->actingAs(User::factory()->create())->get('/horizon')->assertForbidden();
    });

    it('refuses every owner on a saas instance', function () {
        config()->set('noongun.mode', 'saas');

        $this->actingAs($this->owner)->get('/horizon')->assertForbidden();
    });
});

it('applies the gate in the local environment too', function () {
    config()->set('noongun.operators', []);
    $this->app['env'] = 'local';

    $this->actingAs(workspaceMember($this->first, Role::Editor))->get('/horizon')->assertForbidden();
    $this->actingAs($this->owner)->get('/horizon')->assertOk();
});

it('reads the operators from the environment', function () {
    $value = ' One@Example.com ,two@example.com,';
    putenv("NOONGUN_OPERATOR_EMAILS={$value}");
    $_ENV['NOONGUN_OPERATOR_EMAILS'] = $_SERVER['NOONGUN_OPERATOR_EMAILS'] = $value;

    try {
        expect((require config_path('noongun.php'))['operators'])->toBe(['one@example.com', 'two@example.com']);
    } finally {
        putenv('NOONGUN_OPERATOR_EMAILS');
        unset($_ENV['NOONGUN_OPERATOR_EMAILS'], $_SERVER['NOONGUN_OPERATOR_EMAILS']);
    }
});
