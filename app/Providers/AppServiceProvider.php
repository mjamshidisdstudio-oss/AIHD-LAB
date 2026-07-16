<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
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

        // The opaque core-identity user_ref attached by AuthenticateWithCoreToken.
        Request::macro('userRef', function (): ?string {
            /** @var Request $this */
            return $this->attributes->get('user_ref');
        });
    }
}
