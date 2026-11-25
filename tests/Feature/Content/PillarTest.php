<?php

use App\Domain\Content\Models\Idea;
use App\Domain\Content\Models\Pillar;
use App\Domain\Workspaces\Models\Workspace;
use Illuminate\Support\Facades\DB;

itIsolatesWorkspaces(fn () => Pillar::factory()->create());

beforeEach(function () {
    $this->workspace = Workspace::factory()->create();
});

it('has a name, a description and a weekly target', function () {
    $pillar = pillarIn($this->workspace, ['name' => 'DevOps', 'description' => 'How we run things.', 'weekly_target' => 2]);
    $pillar = inWorkspace($this->workspace, fn () => Pillar::findOrFail($pillar->id));

    expect($pillar->name)->toBe('DevOps')
        ->and($pillar->description)->toBe('How we run things.')
        ->and($pillar->weekly_target)->toBe(2);
});

it('plans no posts by default', function () {
    $pillar = inWorkspace($this->workspace, fn () => Pillar::create(['name' => 'Photography']));

    expect($pillar->weekly_target)->toBe(0)
        ->and($pillar->description)->toBeNull();
});

it('has one pillar per name in a workspace, and lets another workspace use the name', function () {
    pillarIn($this->workspace, ['name' => 'DevOps']);

    expectRefusedByDatabase(fn () => pillarIn($this->workspace, ['name' => 'DevOps']));

    expect(pillarIn(Workspace::factory()->create(), ['name' => 'DevOps'])->exists)->toBeTrue();
});

it('keeps its ideas when it is deleted, without their pillar', function () {
    $pillar = pillarIn($this->workspace);
    $idea = ideaIn($this->workspace, $pillar);

    expect(inWorkspace($this->workspace, fn () => $pillar->ideas()->pluck('id')->all()))->toBe([$idea->id]);

    inWorkspace($this->workspace, fn () => $pillar->delete());

    $row = DB::table('ideas')->where('id', $idea->id)->first();

    expect($row->pillar_id)->toBeNull()
        ->and($row->workspace_id)->toBe($this->workspace->id)
        ->and(inWorkspace($this->workspace, fn () => Idea::findOrFail($idea->id))->pillar)->toBeNull();
});
