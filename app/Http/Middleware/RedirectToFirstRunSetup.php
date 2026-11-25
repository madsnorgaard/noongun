<?php

namespace App\Http\Middleware;

use App\Domain\Workspaces\Actions\CompleteFirstRunSetup;
use App\Support\Mode;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * On a fresh self-hosted instance, sends every request to the setup screen
 * until the workspace and its owner account exist.
 */
class RedirectToFirstRunSetup
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! Mode::isSelfHosted() || $request->routeIs('setup.*') || CompleteFirstRunSetup::isComplete()) {
            return $next($request);
        }

        return redirect()->route('setup.create');
    }
}
