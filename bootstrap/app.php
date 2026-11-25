<?php

use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RedirectToFirstRunSetup;
use App\Http\Middleware\ResolveCurrentWorkspace;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: [__DIR__.'/../routes/web.php', __DIR__.'/../routes/channels.php', __DIR__.'/../routes/posts.php', __DIR__.'/../routes/proposals.php'],
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands([
        __DIR__.'/../app/Domain/Scheduling/Console',
        __DIR__.'/../app/Domain/Channels/Console',
        __DIR__.'/../app/Domain/Drafting/Console',
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prependToPriorityList(
            before: AuthenticatesRequests::class,
            prepend: RedirectToFirstRunSetup::class,
        );

        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->web(append: [
            RedirectToFirstRunSetup::class,
            ResolveCurrentWorkspace::class,
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
