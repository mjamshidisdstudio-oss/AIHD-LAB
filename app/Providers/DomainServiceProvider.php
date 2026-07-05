<?php

namespace App\Providers;

use App\Contracts\CoinService;
use App\Contracts\ExternalServiceClient;
use App\Services\Coins\MockCoinService;
use App\Services\External\HttpExternalServiceClient;
use Illuminate\Support\ServiceProvider;

/**
 * Binds swappable domain services to their implementations. This is the single
 * place Phase 5 changes to swap the mock coin service for the real one — the
 * rest of the application depends only on the CoinService interface.
 */
class DomainServiceProvider extends ServiceProvider
{
    /**
     * @var array<class-string, class-string>
     */
    public array $bindings = [
        CoinService::class => MockCoinService::class,
        ExternalServiceClient::class => HttpExternalServiceClient::class,
    ];
}
