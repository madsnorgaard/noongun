<?php

use App\Domain\Workspaces\Models\Membership;
use App\Domain\Workspaces\Models\Workspace;
use Inertia\Testing\AssertableInertia as Assert;

it('shares the user workspace with the dashboard', function () {
    $membership = Membership::factory()->create();

    $this->actingAs($membership->user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('workspace.id', $membership->workspace_id)
            ->where('workspace.timezone', 'Europe/Copenhagen'));
});

it('ignores a session workspace the user is not a member of', function () {
    $membership = Membership::factory()->create();
    $other = Workspace::factory()->create();

    $this->actingAs($membership->user)
        ->withSession(['workspace_id' => $other->id])
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('workspace.id', $membership->workspace_id))
        ->assertSessionHas('workspace_id', $membership->workspace_id);
});
