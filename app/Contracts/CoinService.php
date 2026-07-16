<?php

namespace App\Contracts;

use App\Exceptions\Coins\InsufficientCoinsException;
use App\Exceptions\Core\CoreServiceUnavailableException;

/**
 * Wallet operations for a customer's coin balance, bound to the real core
 * service (Phase 5) at a single point in a service provider; the mock remains
 * available for tests. A charge is a two-step lifecycle because the core is a
 * separate system that cannot join our database transaction:
 *
 *   deduct()  — holds the amount BEFORE our transaction opens; idempotent on
 *               $idempotencyKey (deducting twice with the same key debits once
 *               and returns the same reference).
 *   settle()  — finalizes the hold once the order completes.
 *   refund()  — releases the hold back to the user on failure/timeout, or as a
 *               compensating action if our transaction rolls back after a
 *               successful deduct.
 *
 * Exactly one of settle()/refund() is ever called per transaction reference.
 */
interface CoinService
{
    /**
     * @throws InsufficientCoinsException
     * @throws CoreServiceUnavailableException
     */
    public function deduct(string $userRef, int $amount, string $idempotencyKey): string;

    /**
     * Finalize a previously deducted amount. Idempotent.
     */
    public function settle(string $transactionRef): void;

    /**
     * Release a previously deducted amount back to the user. Idempotent.
     */
    public function refund(string $transactionRef): void;

    /**
     * The user's current balance. Optional pre-submit gating — deduct()'s
     * InsufficientCoinsException is the authoritative check.
     */
    public function balance(string $userRef): int;
}
