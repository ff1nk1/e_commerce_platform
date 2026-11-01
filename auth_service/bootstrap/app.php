<?php

use App\Http\Middleware\EnsureValidAccessToken;
use App\Http\Middleware\RoleMiddleware;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withEvents(false)
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: '*');

        $middleware->redirectGuestsTo(fn () => null);

        $middleware->preventRequestForgery(except: [
            'api/*',
            'admin-catalog',
            'admin-catalog/*',
        ]);

        $middleware->encryptCookies(except: [
            'access_token',
            'refresh_token',
            // Catalog Filament session (Laravel 13: Str::snake(APP_NAME).'_session')
            'catalog_service_session',
            'XSRF-TOKEN',
        ]);

        $middleware->alias([
            'role' => RoleMiddleware::class,
            'ensure.token' => EnsureValidAccessToken::class,
        ]);

        // Authenticate is sorted via AuthenticatesRequests contract — without this,
        // auth:api runs before ensure.token and silent refresh never executes.
        $middleware->prependToPriorityList(
            before: AuthenticatesRequests::class,
            prepend: EnsureValidAccessToken::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(function (
            AuthenticationException $e,
            Request $request
        ) {
            if ($request->expectsJson() || $request->is('api/*') || $request->is('admin-catalog', 'admin-catalog/*')) {
                return response()->json([
                    'message' => 'Unauthenticated.',
                ], 401);
            }
        });
    })->create();
