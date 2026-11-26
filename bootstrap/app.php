<?php

use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RedirectToFirstRunSetup;
use App\Http\Middleware\ResolveCurrentWorkspace;
use App\Http\Middleware\SecurityHeaders;
use App\Support\TrustedHosts;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: [__DIR__.'/../routes/web.php', __DIR__.'/../routes/channels.php', __DIR__.'/../routes/posts.php', __DIR__.'/../routes/media.php', __DIR__.'/../routes/brief.php', __DIR__.'/../routes/llm.php', __DIR__.'/../routes/proposals.php'],
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands([
        __DIR__.'/../app/Domain/Scheduling/Console',
        __DIR__.'/../app/Domain/Channels/Console',
        __DIR__.'/../app/Domain/Drafting/Console',
        __DIR__.'/../app/Domain/Content/Console',
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prependToPriorityList(
            before: AuthenticatesRequests::class,
            prepend: RedirectToFirstRunSetup::class,
        );

        $middleware->trustProxies(headers: Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO);
        $middleware->trustHosts(at: TrustedHosts::patterns(...), subdomains: false);

        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->web(prepend: [SecurityHeaders::class], append: [
            RedirectToFirstRunSetup::class,
            ResolveCurrentWorkspace::class,
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // A rejected API key is never written to the session as old input.
        $exceptions->dontFlash(['api_key']);

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
