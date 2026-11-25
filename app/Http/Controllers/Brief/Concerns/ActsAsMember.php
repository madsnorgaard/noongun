<?php

namespace App\Http\Controllers\Brief\Concerns;

use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;

trait ActsAsMember
{
    protected function actor(Request $request): User
    {
        $user = $request->user();

        abort_unless($user instanceof User, 401);

        return $user;
    }

    /**
     * Whether the user may create records of the given model in the current
     * workspace, which is the rule for changing and deleting them too.
     *
     * @param  class-string  $model
     */
    protected function mayWrite(Request $request, string $model): bool
    {
        return $request->user()?->can('create', $model) ?? false;
    }

    /**
     * @param  'success'|'info'|'warning'|'error'  $type
     */
    protected function toast(string $type, string $message): void
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);
    }
}
