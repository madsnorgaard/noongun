<?php

use App\Domain\Content\Models\Idea;
use App\Domain\Workspaces\Models\Workspace;
use Illuminate\Database\QueryException;

itIsolatesWorkspaces(fn () => Idea::factory()->create());

beforeEach(function () {
    $this->workspace = Workspace::factory()->create();
});

it('has a title, and optionally a source, notes and a pillar', function () {
    $pillar = pillarIn($this->workspace);

    $bare = inWorkspace($this->workspace, fn () => Idea::create(['title' => 'A new roast']));
    $full = ideaIn($this->workspace, $pillar, [
        'title' => 'Why we moved to Postgres',
        'source_url' => 'https://example.com/postgres',
        'notes' => 'Mention the migration weekend.',
    ]);

    $full = inWorkspace($this->workspace, fn () => Idea::with('pillar')->findOrFail($full->id));

    expect($bare->pillar_id)->toBeNull()
        ->and($bare->source_url)->toBeNull()
        ->and($full->title)->toBe('Why we moved to Postgres')
        ->and($full->source_url)->toBe('https://example.com/postgres')
        ->and($full->notes)->toBe('Mention the migration weekend.')
        ->and($full->pillar?->id)->toBe($pillar->id);
});

it('cannot belong to a pillar of another workspace', function () {
    $theirs = pillarIn(Workspace::factory()->create());

    expect(fn () => ideaIn($this->workspace, $theirs))->toThrow(QueryException::class);
});
