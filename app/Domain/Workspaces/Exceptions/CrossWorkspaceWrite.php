<?php

namespace App\Domain\Workspaces\Exceptions;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

class CrossWorkspaceWrite extends RuntimeException
{
    public static function for(Model $model, ?int $currentWorkspaceId): self
    {
        return new self(sprintf(
            'Refusing to write %s #%s: it belongs to workspace %s, the current workspace is %s.',
            $model::class,
            $model->getKey() ?? 'new',
            $model->getOriginal('workspace_id') ?? $model->getAttribute('workspace_id') ?? 'none',
            $currentWorkspaceId ?? 'none',
        ));
    }

    public static function moving(Model $model): self
    {
        return new self(sprintf('Refusing to move %s #%s to another workspace.', $model::class, $model->getKey()));
    }
}
