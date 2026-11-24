<?php

namespace App\Http\Middleware;

use App\Domain\Workspaces\CurrentWorkspace;
use App\Domain\Workspaces\Models\Membership;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sets the current workspace from the session, falling back to the user's
 * first membership. Users only ever get a workspace they are a member of.
 */
class ResolveCurrentWorkspace
{
    public function __construct(private CurrentWorkspace $current) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User) {
            $membership = $this->membershipFor($user, $request->session()->get('workspace_id'));

            if ($membership !== null) {
                $this->current->set($membership->workspace);
                $request->session()->put('workspace_id', $membership->workspace_id);
            }
        }

        return $next($request);
    }

    private function membershipFor(User $user, mixed $workspaceId): ?Membership
    {
        $memberships = $user->memberships()->with('workspace')->orderBy('id');

        if (is_numeric($workspaceId)) {
            $membership = (clone $memberships)->where('workspace_id', (int) $workspaceId)->first();

            if ($membership !== null) {
                return $membership;
            }
        }

        return $memberships->first();
    }
}
