<?php

use App\Http\Middleware\AuthenticateAsGuest;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // The API is consumed only by the first-party SPA on the same origin, so it
        // runs through the "web" group to get session cookies and CSRF protection.
        then: function () {
            Route::middleware('web')
                ->prefix('api')
                ->group(base_path('routes/api.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // No-op when AUTH_GUEST_MODE is off, so normal login is unaffected.
        $middleware->appendToGroup('web', AuthenticateAsGuest::class);

        // Laravel's middleware priority list runs "auth" ahead of the "web"
        // group's own ordering, so without this the guest sign-in above would
        // run after the "auth" route middleware already rejected the request.
        // The priority list keys off the contract, not the concrete
        // Authenticate class, so that's what "before" has to reference.
        $middleware->prependToPriorityList(
            before: AuthenticatesRequests::class,
            prepend: AuthenticateAsGuest::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
