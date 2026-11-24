<?php

use App\Domain\Workspaces\CurrentWorkspace;
use App\Domain\Workspaces\Exceptions\CrossWorkspaceWrite;
use App\Domain\Workspaces\Exceptions\MissingWorkspaceContext;
use App\Domain\Workspaces\Models\Workspace;
use Illuminate\Database\Eloquent\Model;

/**
 * Run a callback with the given workspace as the current workspace.
 *
 * @template TReturn
 *
 * @param  Closure(Workspace): TReturn  $callback
 * @return TReturn
 */
function inWorkspace(Workspace $workspace, Closure $callback): mixed
{
    return app(CurrentWorkspace::class)->run($workspace, $callback);
}

/**
 * Registers the standard workspace isolation tests for a tenant-owned model.
 * Every model that uses BelongsToWorkspace should call this once:
 *
 *     itIsolatesWorkspaces(fn () => Post::factory()->create());
 *
 * The callback runs with a current workspace set and must return a saved
 * model with timestamps. It must not set workspace_id itself.
 *
 * @param  Closure(): Model  $make
 */
function itIsolatesWorkspaces(Closure $make): void
{
    describe('workspace isolation', function () use ($make) {
        beforeEach(function () use ($make) {
            $this->workspaceA = Workspace::factory()->create();
            $this->workspaceB = Workspace::factory()->create();
            $this->record = inWorkspace($this->workspaceA, fn () => $make());
            $this->modelClass = $this->record::class;
        });

        it('fills workspace_id from the current workspace', function () {
            expect($this->record->getAttribute('workspace_id'))->toBe($this->workspaceA->id);
        });

        it('can be read, listed and updated in its own workspace', function () {
            inWorkspace($this->workspaceA, function () {
                expect($this->modelClass::find($this->record->getKey()))->not->toBeNull()
                    ->and($this->modelClass::query()->pluck('id')->all())->toContain($this->record->getKey())
                    ->and($this->modelClass::query()->whereKey($this->record->getKey())->touch())->toBe(1);
            });
        });

        it('cannot be read from another workspace', function () {
            inWorkspace($this->workspaceB, function () {
                expect($this->modelClass::find($this->record->getKey()))->toBeNull()
                    ->and($this->modelClass::query()->whereKey($this->record->getKey())->exists())->toBeFalse();
            });
        });

        it('is not listed in another workspace', function () {
            inWorkspace($this->workspaceB, function () {
                expect($this->modelClass::query()->count())->toBe(0)
                    ->and($this->modelClass::all()->modelKeys())->not->toContain($this->record->getKey());
            });
        });

        it('cannot be updated from another workspace', function () {
            inWorkspace($this->workspaceB, function () {
                expect($this->modelClass::query()->whereKey($this->record->getKey())->touch())->toBe(0);
                expect(fn () => $this->record->touch())->toThrow(CrossWorkspaceWrite::class);
            });
        });

        it('cannot be deleted from another workspace', function () {
            inWorkspace($this->workspaceB, function () {
                expect($this->modelClass::query()->whereKey($this->record->getKey())->delete())->toBe(0);
                expect(fn () => $this->record->delete())->toThrow(CrossWorkspaceWrite::class);
            });

            expect(inWorkspace($this->workspaceA, fn () => $this->modelClass::find($this->record->getKey())))->not->toBeNull();
        });

        it('cannot be moved to another workspace', function () {
            inWorkspace($this->workspaceA, function () {
                $this->record->setAttribute('workspace_id', $this->workspaceB->id);

                expect(fn () => $this->record->save())->toThrow(CrossWorkspaceWrite::class);
            });
        });

        it('shows nothing and cannot be written without a current workspace', function () {
            expect($this->modelClass::query()->count())->toBe(0);
            expect(fn () => $this->record->touch())->toThrow(MissingWorkspaceContext::class);
        });
    });
}
