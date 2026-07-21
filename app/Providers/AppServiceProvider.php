<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Authorization for the catalog admin API.
        Gate::define('manage-catalog', fn (User $user) => $user->isAdmin());

        Gate::define('viewLogViewer', fn (?User $user): bool => $user?->is_admin === true);

        // The opaque core-identity user_ref attached by AuthenticateWithCoreToken.
        Request::macro('userRef', function (): ?string {
            /** @var Request $this */
            return $this->attributes->get('user_ref');
        });

        $this->registerRateLimiters();
    }

    /**
     * Public-endpoint rate limits, keyed by the core-identity user_ref (never
     * IP alone -- these routes always sit behind auth.core, so a real
     * identity is always available). Limits reflect how expensive/abusable
     * each action is: submitting an order deducts coins and dispatches real
     * external work, so it's the tightest; downloads are the most benign
     * (legitimate retries happen) and get the most headroom.
     */
    private function registerRateLimiters(): void
    {
        RateLimiter::for('submit-order', fn (Request $request) => Limit::perMinute(10)->by($request->userRef() ?? $request->ip()));
        RateLimiter::for('vote', fn (Request $request) => Limit::perMinute(20)->by($request->userRef() ?? $request->ip()));
        RateLimiter::for('comment', fn (Request $request) => Limit::perMinute(10)->by($request->userRef() ?? $request->ip()));
        RateLimiter::for('download', fn (Request $request) => Limit::perMinute(30)->by($request->userRef() ?? $request->ip()));
    }
}
