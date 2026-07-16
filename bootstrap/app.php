<?php

use App\Exceptions\Auth\InvalidTokenException;
use App\Exceptions\Catalog\CatalogException;
use App\Exceptions\Coins\InsufficientCoinsException;
use App\Exceptions\Core\CoreServiceUnavailableException;
use App\Http\Middleware\AuthenticateWithCoreToken;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
        then: function () {
            // Local mocks of external systems; never mounted in production.
            if (! app()->isProduction()) {
                Route::middleware('api')->group(base_path('routes/dev.php'));
                Route::middleware('api')->group(base_path('routes/core-stub.php'));
            }
        },
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->statefulApi();
        $middleware->alias([
            'auth.core' => AuthenticateWithCoreToken::class,
        ]);
        // Authenticate before route-model binding resolves {order}, so a
        // missing/invalid token is rejected as 401 rather than leaking a 404
        // for an order that may or may not exist.
        $middleware->prependToPriorityList(
            before: SubstituteBindings::class,
            prepend: AuthenticateWithCoreToken::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Surface catalog/versioning domain rule violations as JSON for the API.
        $exceptions->render(function (CatalogException $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json(['message' => $e->getMessage()], $e->status);
            }

            return null;
        });

        $exceptions->render(function (InsufficientCoinsException $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json(['message' => $e->getMessage()], $e->status);
            }

            return null;
        });

        $exceptions->render(function (InvalidTokenException $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json(['message' => $e->getMessage()], $e->status);
            }

            return null;
        });

        $exceptions->render(function (CoreServiceUnavailableException $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json(['message' => $e->getMessage()], $e->status);
            }

            return null;
        });
    })->create();
