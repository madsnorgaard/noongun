<?php

namespace App\Domain\Workspaces\Actions;

use App\Domain\Workspaces\Models\Membership;
use App\Domain\Workspaces\Models\Workspace;
use App\Domain\Workspaces\Role;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Creates the owner and the single workspace of a self-hosted instance.
 */
class CompleteFirstRunSetup
{
    /**
     * @param  array{name: string, email: string, password: string, workspace_name: string, timezone: string, locale: string}  $input
     */
    public function handle(array $input): User
    {
        return Cache::lock('noongun:first-run-setup', 10)->block(5, function () use ($input): User {
            if (static::isComplete()) {
                throw new NotFoundHttpException;
            }

            return DB::transaction(function () use ($input): User {
                $user = User::query()->forceCreate([
                    'name' => $input['name'],
                    'email' => $input['email'],
                    'password' => $input['password'],
                    'locale' => $input['locale'],
                    'email_verified_at' => now(),
                ]);

                $workspace = Workspace::query()->create([
                    'name' => $input['workspace_name'],
                    'timezone' => $input['timezone'],
                    'locale' => $input['locale'],
                ]);

                Membership::query()->create([
                    'workspace_id' => $workspace->id,
                    'user_id' => $user->id,
                    'role' => Role::Owner,
                ]);

                return $user;
            });
        });
    }

    /**
     * Setup is done once a workspace exists, and it stays done if every
     * account is gone: the workspace and what it holds must not fall to
     * whoever opens the setup screen next. An account alone closes it too.
     */
    public static function isComplete(): bool
    {
        return Workspace::query()->exists() || User::query()->exists();
    }
}
