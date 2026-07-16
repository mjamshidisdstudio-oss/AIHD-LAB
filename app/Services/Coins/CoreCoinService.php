<?php

namespace App\Services\Coins;

use App\Contracts\CoinService;
use App\Exceptions\Coins\InsufficientCoinsException;
use App\Exceptions\Core\CoreServiceUnavailableException;
use App\Support\Core\CoreApiClient;

/**
 * The real coin wallet, calling the core team's HTTP endpoints. Bound as the
 * default CoinService (see DomainServiceProvider) — locally/in tests it points
 * at the in-app LocalCoreStub; only config changes when the real core arrives.
 */
class CoreCoinService implements CoinService
{
    public function __construct(private readonly CoreApiClient $client) {}

    public function deduct(string $userRef, int $amount, string $idempotencyKey): string
    {
        $response = $this->client->post('/coins/deduct', [
            'user_ref' => $userRef,
            'amount' => $amount,
            'idempotency_key' => $idempotencyKey,
        ], operation: 'coins.deduct');

        if ($response->status() === 402) {
            throw InsufficientCoinsException::for($userRef, $amount);
        }

        if ($response->failed()) {
            throw CoreServiceUnavailableException::unreachable('coins.deduct');
        }

        return (string) $response->json('txn_ref');
    }

    public function settle(string $transactionRef): void
    {
        $response = $this->client->post('/coins/settle', [
            'txn_ref' => $transactionRef,
        ], operation: 'coins.settle');

        if ($response->failed()) {
            throw CoreServiceUnavailableException::unreachable('coins.settle');
        }
    }

    public function refund(string $transactionRef): void
    {
        $response = $this->client->post('/coins/refund', [
            'txn_ref' => $transactionRef,
        ], operation: 'coins.refund');

        if ($response->failed()) {
            throw CoreServiceUnavailableException::unreachable('coins.refund');
        }
    }

    public function balance(string $userRef): int
    {
        $response = $this->client->get('/coins/balance', [
            'user_ref' => $userRef,
        ], operation: 'coins.balance');

        if ($response->failed()) {
            throw CoreServiceUnavailableException::unreachable('coins.balance');
        }

        return (int) $response->json('balance');
    }
}
