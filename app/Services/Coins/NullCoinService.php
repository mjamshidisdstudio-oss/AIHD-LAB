<?php

namespace App\Services\Coins;

use App\Contracts\CoinService;

/**
 * Bound in place of CoreCoinService when config('lab.billing_enabled') is
 * false (Phase L2 launch mode) -- billing is switched off, not removed.
 * Every operation is a silent no-op: nothing is ever actually charged,
 * settled, or refunded, and balance is unlimited. deduct() is deterministic
 * on $idempotencyKey (same key -> same fake ref) purely to honor the
 * interface's documented idempotency contract; there is no real state
 * behind it to make idempotent.
 */
class NullCoinService implements CoinService
{
    public function deduct(string $userRef, int $amount, string $idempotencyKey): string
    {
        return "null-txn:{$idempotencyKey}";
    }

    public function settle(string $transactionRef): void
    {
        // Nothing was ever charged -- nothing to settle.
    }

    public function refund(string $transactionRef): void
    {
        // Nothing was ever charged -- nothing to refund.
    }

    public function balance(string $userRef): int
    {
        return PHP_INT_MAX;
    }
}
