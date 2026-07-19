<?php

namespace App\Providers;

use App\Contracts\CoinService;
use App\Contracts\ExternalServiceClient;
use App\Contracts\TokenAuthenticator;
use App\Services\Auth\CoreTokenAuthenticator;
use App\Services\Coins\CoreCoinService;
use App\Services\Coins\NullCoinService;
use App\Services\External\HttpExternalServiceClient;
use App\Support\Core\CoreApiClient;
use Illuminate\Support\ServiceProvider;

/**
 * Binds swappable domain services to their implementations. TokenAuthenticator
 * is bound to the real (HTTP) implementation everywhere — this is Phase 5's
 * "single-point swap": when the real core team endpoints are available, only
 * config/core.php's base_url/credential change, not this binding. Tests either
 * fake the HTTP layer or swap in the Mock* doubles.
 *
 * CoinService is the one binding that is itself conditional (Phase L2): real
 * CoreCoinService unless config('lab.billing_enabled') is false, in which
 * case NullCoinService (every operation a silent no-op) takes over. Flipping
 * LAB_BILLING_ENABLED back to true restores the real path with no code
 * change — see register() below, not the static $bindings array. The
 * closure reads config('lab.billing_enabled') on every resolution (this is
 * bind(), not singleton()), not once at boot, so the flag can flip mid
 * process — real deployments only ever set it once, at boot, via env, but
 * this is what lets a test prove the flip is genuinely live rather than
 * baked in.
 */
class DomainServiceProvider extends ServiceProvider
{
    /**
     * @var array<class-string, class-string>
     */
    public array $bindings = [
        TokenAuthenticator::class => CoreTokenAuthenticator::class,
        ExternalServiceClient::class => HttpExternalServiceClient::class,
    ];

    public function register(): void
    {
        $this->app->bind(CoinService::class, fn ($app) => config('lab.billing_enabled', true)
            ? $app->make(CoreCoinService::class)
            : $app->make(NullCoinService::class));

        $this->app->singleton(CoreApiClient::class, fn () => new CoreApiClient(
            baseUrl: config('core.base_url'),
            credential: config('core.credential'),
            timeoutSeconds: config('core.timeout_seconds'),
            connectTimeoutSeconds: config('core.connect_timeout_seconds'),
        ));
    }
}
