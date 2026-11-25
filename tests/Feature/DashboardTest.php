<?php

use App\Domain\Drafting\Models\DraftRun;
use App\Domain\Workspaces\Models\Workspace;
use App\Domain\Workspaces\Role;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../Support/members.php';
require_once __DIR__.'/../Support/publishing.php';
require_once __DIR__.'/../Support/posts.php';

test('guests are redirected to the login page', function () {
    User::factory()->create();

    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertOk();
});

describe('the path to a first week', function () {
    beforeEach(function () {
        $this->workspace = Workspace::factory()->create();
        $this->owner = workspaceMember($this->workspace, Role::Owner);
    });

    it('lists what is done and what is not, in order, from what exists', function () {
        $steps = fn (bool ...$done) => array_map(
            fn (string $key, bool $isDone) => ['key' => $key, 'done' => $isDone],
            ['channel', 'brief', 'slots', 'key', 'propose'],
            $done,
        );

        $this->actingAs($this->owner)->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->where('setup', $steps(false, false, false, false, false))
                ->where('can.propose', true));

        $channel = channelIn($this->workspace);
        briefIn($this->workspace);

        $this->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page->where('setup', $steps(true, true, false, false, false)));

        slotIn($this->workspace, $channel);
        fakeDraftGenerator();

        $this->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page->where('setup', $steps(true, true, true, true, false)));
    });

    it('does not count a channel that is disconnected or not confirmed', function (string $status) {
        channelIn($this->workspace, ['status' => $status, 'access_token' => null]);

        $this->actingAs($this->owner)->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page->where('setup.0', ['key' => 'channel', 'done' => false]));
    })->with(['disconnected', 'pending']);

    it('goes away once everything is done', function () {
        fakeDraftGenerator();
        slotIn($this->workspace, channelIn($this->workspace));
        briefIn($this->workspace);
        draftRunIn($this->workspace, $this->owner);

        expect(inWorkspace($this->workspace, fn () => DraftRun::query()->count()))->toBe(1);

        $this->actingAs($this->owner)->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page->where('setup', null));
    });

    it('is counted for the workspace of the user only', function () {
        $elsewhere = Workspace::factory()->create();
        slotIn($elsewhere, channelIn($elsewhere));
        briefIn($elsewhere);

        $this->actingAs($this->owner)->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('setup.0.done', false)
                ->where('setup.1.done', false)
                ->where('setup.2.done', false));
    });

    it('tells a viewer the same, without the right to propose', function () {
        $this->actingAs(workspaceMember($this->workspace, Role::Viewer))->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('setup', 5)
                ->where('can.propose', false));
    });

    it('is empty for someone without a workspace', function () {
        $this->actingAs(User::factory()->create())->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('setup', null)->where('can.propose', false));
    });
});
