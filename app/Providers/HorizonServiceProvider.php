<?php

namespace App\Providers;

use App\Domain\Workspaces\Models\Workspace;
use App\Domain\Workspaces\Role;
use App\Models\User;
use App\Support\Mode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Horizon lets everyone in when the environment is local. This instance
     * does not: the gate decides in every environment.
     */
    protected function authorization(): void
    {
        $this->gate();

        Horizon::auth(fn (Request $request): bool => Gate::forUser($request->user())->check('viewHorizon'));
    }

    /**
     * Only the operators of the instance may open the Horizon dashboard. It
     * shows the jobs of every workspace.
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', fn (?User $user = null): bool => $user !== null && $this->isOperator($user));
    }

    private function isOperator(User $user): bool
    {
        /** @var list<string> $operators */
        $operators = config('noongun.operators', []);

        if ($operators !== []) {
            return $user->hasVerifiedEmail() && in_array(mb_strtolower($user->email), $operators, true);
        }

        if (! Mode::isSelfHosted()) {
            return false;
        }

        return Workspace::query()->oldest('id')->first()?->roleOf($user) === Role::Owner;
    }
}
