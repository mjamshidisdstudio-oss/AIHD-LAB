<?php

namespace App\Support\Core;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * In-memory (Cache-backed) state for the LocalCoreStub — balances, held/settled
 * /refunded transactions, and registered auth tokens. Shared by the stub's HTTP
 * controller and directly importable by tests that want to arrange fixtures
 * (seed a balance, register a token) without a round trip through HTTP.
 */
class LocalCoreStubState
{
    public static function registerToken(string $token, string $userRef): void
    {
        Cache::forever(self::tokenKey($token), $userRef);
    }

    /**
     * Resolve a token to its user_ref: an explicitly registered token, the
     * fixed config-driven dev token, or null (invalid).
     */
    public static function resolveToken(string $token): ?string
    {
        $registered = Cache::get(self::tokenKey($token));
        if ($registered !== null) {
            return $registered;
        }

        if ($token === config('core.stub.dev_token')) {
            return config('core.stub.dev_user_ref');
        }

        return null;
    }

    public static function seedBalance(string $userRef, int $balance): void
    {
        Cache::forever(self::balanceKey($userRef), $balance);
    }

    public static function balance(string $userRef): int
    {
        return (int) Cache::get(self::balanceKey($userRef), (int) config('core.stub.seed_balance'));
    }

    /**
     * Hold (deduct) an amount, idempotent on $idempotencyKey. Returns the txn
     * ref. Throws if the balance is insufficient.
     *
     * @throws InsufficientStubBalanceException
     */
    public static function deduct(string $userRef, int $amount, string $idempotencyKey): string
    {
        $existingTxnRef = Cache::get(self::idempotencyKey($idempotencyKey));
        if ($existingTxnRef !== null) {
            return $existingTxnRef;
        }

        return Cache::lock(self::lockKey($userRef), 5)->block(2, function () use ($userRef, $amount, $idempotencyKey) {
            $balance = self::balance($userRef);
            if ($balance < $amount) {
                throw new InsufficientStubBalanceException;
            }

            $txnRef = (string) Str::uuid();
            Cache::forever(self::balanceKey($userRef), $balance - $amount);
            Cache::forever(self::txnKey($txnRef), ['user_ref' => $userRef, 'amount' => $amount, 'status' => 'held']);
            Cache::forever(self::idempotencyKey($idempotencyKey), $txnRef);

            return $txnRef;
        });
    }

    /**
     * Finalize a held transaction. Idempotent — settling twice is a no-op.
     */
    public static function settle(string $txnRef): void
    {
        $txn = Cache::get(self::txnKey($txnRef));
        if ($txn === null || $txn['status'] !== 'held') {
            return;
        }

        Cache::forever(self::txnKey($txnRef), [...$txn, 'status' => 'settled']);
    }

    /**
     * Release a held transaction's amount back to the balance. Idempotent —
     * refunding twice (or refunding an already-settled transaction) never
     * double-credits.
     */
    public static function refund(string $txnRef): void
    {
        $txn = Cache::get(self::txnKey($txnRef));
        if ($txn === null || $txn['status'] !== 'held') {
            return;
        }

        Cache::lock(self::lockKey($txn['user_ref']), 5)->block(2, function () use ($txnRef, $txn) {
            Cache::forever(self::balanceKey($txn['user_ref']), self::balance($txn['user_ref']) + $txn['amount']);
            Cache::forever(self::txnKey($txnRef), [...$txn, 'status' => 'refunded']);
        });
    }

    private static function tokenKey(string $token): string
    {
        return "core-stub:token:{$token}";
    }

    private static function balanceKey(string $userRef): string
    {
        return "core-stub:balance:{$userRef}";
    }

    private static function txnKey(string $txnRef): string
    {
        return "core-stub:txn:{$txnRef}";
    }

    private static function idempotencyKey(string $idempotencyKey): string
    {
        return "core-stub:idempotency:{$idempotencyKey}";
    }

    private static function lockKey(string $userRef): string
    {
        return "core-stub:lock:{$userRef}";
    }
}
