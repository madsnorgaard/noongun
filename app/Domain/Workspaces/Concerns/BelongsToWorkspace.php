<?php

namespace App\Domain\Workspaces\Concerns;

use App\Domain\Workspaces\CurrentWorkspace;
use App\Domain\Workspaces\Exceptions\CrossWorkspaceWrite;
use App\Domain\Workspaces\Exceptions\MissingWorkspaceContext;
use App\Domain\Workspaces\Models\Workspace;
use App\Domain\Workspaces\Scopes\WorkspaceScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * For every tenant-owned model. Scopes reads to the current workspace, fills
 * workspace_id on create, and refuses writes that cross workspaces.
 *
 * @mixin Model
 */
trait BelongsToWorkspace
{
    public static function bootBelongsToWorkspace(): void
    {
        static::addGlobalScope(new WorkspaceScope);

        static::creating(function (Model $model): void {
            $currentId = app(CurrentWorkspace::class)->id() ?? throw new MissingWorkspaceContext;
            $given = $model->getAttribute('workspace_id');

            if ($given === null) {
                $model->setAttribute('workspace_id', $currentId);
            } elseif ((int) $given !== $currentId) {
                throw CrossWorkspaceWrite::for($model, $currentId);
            }
        });

        static::saving(function (Model $model): void {
            if (! $model->exists) {
                return;
            }

            if ($model->isDirty('workspace_id')) {
                throw CrossWorkspaceWrite::moving($model);
            }

            static::guardWorkspace($model);
        });

        static::deleting(function (Model $model): void {
            static::guardWorkspace($model);
        });
    }

    protected static function guardWorkspace(Model $model): void
    {
        $currentId = app(CurrentWorkspace::class)->id() ?? throw new MissingWorkspaceContext;

        if ((int) $model->getOriginal('workspace_id') !== $currentId) {
            throw CrossWorkspaceWrite::for($model, $currentId);
        }
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
