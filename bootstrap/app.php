<?php

use App\Exceptions\Auth\InvalidTokenException;
use App\Exceptions\Catalog\CatalogException;
use App\Exceptions\Coins\InsufficientCoinsException;
use App\Exceptions\Core\CoreServiceUnavailableException;
use App\Exceptions\Storage\MediaValidationException;
use App\Http\Middleware\SiteAuth;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
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

        // Phase L2 launch mode: with no core-team login available yet,
        // LAB_AUTH_MODE=anonymous swaps the real auth path to AnonymousAuth (a
        // stable per-browser identity, no token) instead of
        // AuthenticateWithCoreToken (a real core bearer token). 'auth.core'
        // always resolves to SiteAuth, a thin gate that decides between the
        // two at request time -- see that class for why the decision can't be
        // made here, at boot. Every route using 'auth.core' picks up a flag
        // flip with no route-file change, and flipping it back is a config
        // change, not a code change. AuthenticateWithCoreToken itself is
        // untouched either way.
        $middleware->alias([
            'auth.core' => SiteAuth::class,
        ]);
        // Authenticate before route-model binding resolves {order}, so a
        // missing/invalid token is rejected as 401 rather than leaking a 404
        // for an order that may or may not exist. Also before ThrottleRequests
        // (which sits ahead of SubstituteBindings in the framework's default
        // priority list) -- the public rate limiters key by userRef(), which
        // this middleware is what actually populates; running after it would
        // silently fall back to IP-keyed throttling for every authenticated
        // request.
        $middleware->prependToPriorityList(
            before: ThrottleRequests::class,
            prepend: SiteAuth::class,
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

        // Our own input-upload path (StoreMedia via SubmitOrder) never
        // distinguishes a mime-mismatch from an oversized file -- both are
        // 422 here. POST /api/storage (StorageController) catches this
        // exception directly instead, before it ever reaches this callback,
        // since that endpoint's contract does distinguish the two (413 vs 422).
        $exceptions->render(function (MediaValidationException $e, Request $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json(['message' => $e->getMessage()], 422);
            }

            return null;
        });
    })->create();
