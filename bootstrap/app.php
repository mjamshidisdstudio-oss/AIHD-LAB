<?php

use App\Exceptions\Catalog\CatalogException;
use App\Exceptions\Coins\InsufficientCoinsException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
        then: function () {
            // Local mock of an external provider; never mounted in production.
            if (! app()->isProduction()) {
                Route::middleware('api')->group(base_path('routes/dev.php'));
            }
        },
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->statefulApi();
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
    })->create();
