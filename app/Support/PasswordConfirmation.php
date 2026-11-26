<?php

namespace App\Support;

use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;

/**
 * Answers for a page what RequirePassword would decide for its next
 * request: whether the password was confirmed recently enough, and for how
 * long that still holds.
 *
 * It extends the middleware and is built with the same timeout
 * (AppServiceProvider), so a page and the routes it submits to cannot
 * disagree. It guards nothing itself: the routes keep RequirePassword.
 */
final class PasswordConfirmation extends RequirePassword
{
    public function holds(Request $request): bool
    {
        return ! $this->shouldConfirmPassword($request);
    }

    /**
     * Whole seconds until the middleware asks again, or null when it
     * already would.
     */
    public function secondsLeft(Request $request): ?int
    {
        if (! $this->holds($request)) {
            return null;
        }

        $confirmedAt = $request->session()->get('auth.password_confirmed_at', 0);

        return max(0, (is_numeric($confirmedAt) ? (int) $confirmedAt : 0) + (int) $this->passwordTimeout - Date::now()->unix());
    }
}
