<?php

namespace App\Services\Coins;

use App\Contracts\CoinService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * A stand-in wallet with unlimited funds. Charges are idempotent on the key and
 * remembered so refunds can be reasoned about in tests. Phase 5 replaces this
 * binding with the real coin service; nothing else changes.
 */
class MockCoinService implements CoinService
{
    public function charge(string $userRef, int $amount, string $idempotencyKey): string
    {
        // Idempotent: the same key always maps to the same transaction ref.
        return Cache::rememberForever(
            "mock-coins:charge:{$idempotencyKey}",
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
