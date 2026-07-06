<?php

namespace App\Contracts;

use App\Exceptions\Coins\InsufficientCoinsException;

/**
 * Wallet operations for charging/refunding order coins. The mock implementation
 * is used through Phase 4; Phase 5 binds the real service at a single point in a
 * service provider. Charges happen OUTSIDE database transactions (the real
 * provider is a separate system that cannot join one), so charge() must be
 * idempotent on $idempotencyKey.
 */
interface CoinService
{
    /**
     * Charge a user and return an opaque transaction reference. Idempotent:
     * charging twice with the same key returns the same reference and debits
     * once.
     *
     * @throws InsufficientCoinsException
     */
    public function charge(string $userRef, int $amount, string $idempotencyKey): string;

    /**
     * Refund a previous charge by its transaction reference. Idempotent.
     */
    public function refund(string $transactionRef): void;
}
