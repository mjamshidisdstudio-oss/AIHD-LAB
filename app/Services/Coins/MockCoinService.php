<?php

namespace App\Services\Coins;

use App\Contracts\CoinService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * A stand-in wallet with unlimited funds, for tests that want a fast in-memory
 * double instead of exercising the real HTTP CoreCoinService. Deducts are
 * idempotent on the key and remembered so settle/refund can be reasoned about.
 */
class MockCoinService implements CoinService
{
    public function deduct(string $userRef, int $amount, string $idempotencyKey): string
    {
        // Idempotent: the same key always maps to the same transaction ref.
        return Cache::rememberForever(
            "mock-coins:deduct:{$idempotencyKey}",
            fn () => (string) Str::uuid(),
        );
    }

    public function settle(string $transactionRef): void
    {
        Cache::forever("mock-coins:settle:{$transactionRef}", true);
    }

    public function refund(string $transactionRef): void
    {
        Cache::forever("mock-coins:refund:{$transactionRef}", true);
    }

    public function balance(string $userRef): int
    {
        return PHP_INT_MAX;
    }

    /**
     * Test helper: was this transaction settled?
     */
    public function wasSettled(string $transactionRef): bool
    {
        return (bool) Cache::get("mock-coins:settle:{$transactionRef}", false);
    }

    /**
     * Test helper: was this transaction refunded?
     */
    public function wasRefunded(string $transactionRef): bool
    {
        return (bool) Cache::get("mock-coins:refund:{$transactionRef}", false);
    }
}
