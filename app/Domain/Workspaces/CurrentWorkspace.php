<?php

namespace App\Domain\Workspaces;

use App\Domain\Workspaces\Exceptions\MissingWorkspaceContext;
use App\Domain\Workspaces\Models\Workspace;
use Closure;

/**
 * Holds the workspace that tenant-owned queries are scoped to.
 *
 * Set by the ResolveCurrentWorkspace middleware for web requests. Jobs and
 * commands must set it explicitly, usually through run().
 */
final class CurrentWorkspace
{
    private ?Workspace $workspace = null;

    public function set(?Workspace $workspace): void
    {
        $this->workspace = $workspace;
    }

    public function get(): ?Workspace
    {
        return $this->workspace;
    }

    public function id(): ?int
    {
        return $this->workspace?->id;
    }

    public function getOrFail(): Workspace
    {
        return $this->workspace ?? throw new MissingWorkspaceContext;
    }

    /**
     * @template TReturn
     *
     * @param  Closure(Workspace): TReturn  $callback
     * @return TReturn
     */
    public function run(Workspace $workspace, Closure $callback): mixed
    {
        $previous = $this->workspace;
        $this->workspace = $workspace;

        try {
            return $callback($workspace);
        } finally {
            $this->workspace = $previous;
        }
    }
}
