<?php

namespace App\Domain\Workspaces\Scopes;

use App\Domain\Workspaces\CurrentWorkspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Limits queries to the current workspace. Without a current workspace the
 * query matches nothing, so a missing context fails closed instead of
 * leaking rows from every tenant.
 *
 * @implements Scope<Model>
 */
class WorkspaceScope implements Scope
{
    /**
     * @param  Builder<covariant Model>  $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        $workspaceId = app(CurrentWorkspace::class)->id();

        if ($workspaceId === null) {
            $builder->whereRaw('1 = 0');

            return;
        }

        $builder->where($model->qualifyColumn('workspace_id'), $workspaceId);
    }
}
