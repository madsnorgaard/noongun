<?php

namespace App\Http\Controllers;

use App\Domain\Workspaces\Actions\CompleteFirstRunSetup;
use App\Http\Requests\FirstRunSetupRequest;
use App\Support\Mode;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class FirstRunSetupController extends Controller
{
    public function create(): Response
    {
        $this->ensureSetupIsOpen();

        $timezones = (array) config('noongun.timezones');

        return Inertia::render('setup/FirstRun', [
            'timezones' => array_values($timezones),
            'allTimezones' => array_values(array_diff(timezone_identifiers_list(), $timezones)),
            'defaultTimezone' => config('noongun.default_timezone'),
            'locales' => config('noongun.locales'),
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]);
    }

    public function store(FirstRunSetupRequest $request, CompleteFirstRunSetup $setup): RedirectResponse
    {
        $this->ensureSetupIsOpen();

        /** @var array{name: string, email: string, password: string, workspace_name: string, timezone: string, locale: string} $input */
        $input = $request->safe()->only(['name', 'email', 'password', 'workspace_name', 'timezone', 'locale']);

        $user = $setup->handle($input);

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('dashboard');
    }

    private function ensureSetupIsOpen(): void
    {
        abort_unless(Mode::isSelfHosted() && ! CompleteFirstRunSetup::isComplete(), 404);
    }
}
