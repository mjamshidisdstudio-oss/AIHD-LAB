<?php

namespace App\Providers;

use App\Contracts\CoinService;
use App\Contracts\ExternalServiceClient;
use App\Contracts\TokenAuthenticator;
use App\Services\Auth\CoreTokenAuthenticator;
use App\Services\Coins\CoreCoinService;
use App\Services\External\HttpExternalServiceClient;
use App\Support\Core\CoreApiClient;
use Illuminate\Support\ServiceProvider;

/**
 * Binds swappable domain services to their implementations. CoinService and
 * TokenAuthenticator are bound to the real (HTTP) implementations everywhere —
 * this is Phase 5's "single-point swap": when the real core team endpoints are
 * available, only config/core.php's base_url/credential change, not this
 * binding. Tests either fake the HTTP layer or swap in the Mock* doubles.
 */
class DomainServiceProvider extends ServiceProvider
{
    /**
     * @var array<class-string, class-string>
     */
    public array $bindings = [
        CoinService::class => CoreCoinService::class,
        TokenAuthenticator::class => CoreTokenAuthenticator::class,
        ExternalServiceClient::class => HttpExternalServiceClient::class,
    ];

    public function register(): void
    {
        $this->app->singleton(CoreApiClient::class, fn () => new CoreApiClient(
            baseUrl: config('core.base_url'),
            credential: config('core.credential'),
            timeoutSeconds: config('core.timeout_seconds'),
            connectTimeoutSeconds: config('core.connect_timeout_seconds'),
        ));
    }
}
