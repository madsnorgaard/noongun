<?php

use App\Domain\Workspaces\Exceptions\CrossWorkspaceWrite;
use App\Domain\Workspaces\Exceptions\MissingWorkspaceContext;
use App\Domain\Workspaces\Models\Workspace;
use Tests\Fixtures\Note;

beforeEach(fn () => Note::createTable());

itIsolatesWorkspaces(fn () => Note::create(['body' => 'Hello']));

it('refuses to create a record for another workspace than the current one', function () {
    $a = Workspace::factory()->create();
    $b = Workspace::factory()->create();

    inWorkspace($a, function () use ($b) {
        expect(fn () => Note::create(['body' => 'Sneaky', 'workspace_id' => $b->id]))
            ->toThrow(CrossWorkspaceWrite::class);
    });
});

it('refuses to create a record without a current workspace', function () {
    expect(fn () => Note::create(['body' => 'Orphan']))->toThrow(MissingWorkspaceContext::class);
});
