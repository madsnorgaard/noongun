<?php

namespace App\Domain\Workspaces;

enum Role: string
{
    case Owner = 'owner';
    case Editor = 'editor';
    case Viewer = 'viewer';

    public function canManageWorkspace(): bool
    {
        return $this === self::Owner;
    }

    public function canEditContent(): bool
    {
        return $this === self::Owner || $this === self::Editor;
    }

    public function canView(): bool
    {
        return true;
    }
}
