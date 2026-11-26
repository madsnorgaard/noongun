<?php

namespace App\Providers;

use App\Domain\Channels\ChannelManager;
use App\Domain\Drafting\DraftGenerators;
use App\Domain\Drafting\Generators\ProviderReplies;
use App\Domain\Drafting\PromptRenderer;
use App\Domain\Workspaces\CurrentWorkspace;
use App\Support\Health\CheckBackingServices;
use App\Support\PasswordConfirmation;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(CurrentWorkspace::class);
        $this->app->singleton(ChannelManager::class);
        $this->app->scoped(DraftGenerators::class);
        $this->app->singleton(ProviderReplies::class);

        // Built the way the framework builds RequirePassword, from the same
        // setting, so that a page says what the middleware will decide.
        $this->app->bind(PasswordConfirmation::class, fn (Application $app): PasswordConfirmation => new PasswordConfirmation(
            $app->make(ResponseFactory::class),
            $app->make(UrlGenerator::class),
            $app->make('config')->get('auth.password_timeout'),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        Event::listen(DiagnosingHealth::class, CheckBackingServices::class);

        View::addNamespace(PromptRenderer::NAMESPACE, resource_path('prompts'));

        $this->configureRateLimits();
    }

    /**
     * "proposals": starting a week proposal and stopping one, six times a
     * minute each for a user. A request over the limit goes back to the
     * page it came from with a sentence, where a bare 429 would open an
     * error page over the plan.
     */
    protected function configureRateLimits(): void
    {
        RateLimiter::for('proposals', fn (Request $request): Limit => Limit::perMinute(6)
            ->by(($request->user()?->getAuthIdentifier() ?? $request->ip()).'|'.$request->route()?->getName())
            ->response(function () {
                $message = (string) __('That was asked too often in a short time. Wait a minute and try again.');

                Inertia::flash('toast', ['type' => 'warning', 'message' => $message]);

                return back(fallback: route('queue.index'))->withErrors(['proposal' => $message]);
            }));
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
