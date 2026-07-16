<?php

namespace App\Http\Controllers\Dev;

use App\Http\Controllers\Controller;
use App\Support\Core\InsufficientStubBalanceException;
use App\Support\Core\LocalCoreStubState;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A local stand-in for the core team's identity + coin service, so the full
 * auth -> submit -> deduct -> complete -> settle loop runs end-to-end without
 * the real core. Only mounted outside production (see bootstrap/app.php).
 * config('core.base_url') points here by default; swapping to the real core
 * later is a config-only change (see CoreApiClient/CoreCoinService).
 *
 *   POST /dev/core/verify-token    {token}                 -> {user_ref} | 401
 *   POST /dev/core/coins/deduct    {user_ref,amount,...}    -> {txn_ref}  | 402
 *   POST /dev/core/coins/settle    {txn_ref}                -> {ok}
 *   POST /dev/core/coins/refund    {txn_ref}                -> {ok}
 *   GET  /dev/core/coins/balance   ?user_ref=               -> {balance}
 */
class LocalCoreStubController extends Controller
{
    private function rejectUnlessCredentialed(Request $request): ?JsonResponse
    {
        if ($request->bearerToken() !== config('core.credential')) {
            return response()->json(['message' => 'Invalid service credential.'], 401);
        }

        return null;
    }

    public function verifyToken(Request $request): JsonResponse
    {
        if ($rejected = $this->rejectUnlessCredentialed($request)) {
            return $rejected;
        }

        $userRef = LocalCoreStubState::resolveToken((string) $request->input('token'));

        if ($userRef === null) {
            return response()->json(['message' => 'Invalid token.'], 401);
        }

        return response()->json(['user_ref' => $userRef]);
    }

    public function deduct(Request $request): JsonResponse
    {
        if ($rejected = $this->rejectUnlessCredentialed($request)) {
            return $rejected;
        }

        try {
            $txnRef = LocalCoreStubState::deduct(
                (string) $request->input('user_ref'),
                (int) $request->input('amount'),
                (string) $request->input('idempotency_key'),
            );
        } catch (InsufficientStubBalanceException) {
            return response()->json(['message' => 'Insufficient balance.'], 402);
        }

        return response()->json(['txn_ref' => $txnRef]);
    }

    public function settle(Request $request): JsonResponse
    {
        if ($rejected = $this->rejectUnlessCredentialed($request)) {
            return $rejected;
        }

        LocalCoreStubState::settle((string) $request->input('txn_ref'));

        return response()->json(['ok' => true]);
    }

    public function refund(Request $request): JsonResponse
    {
        if ($rejected = $this->rejectUnlessCredentialed($request)) {
            return $rejected;
        }

        LocalCoreStubState::refund((string) $request->input('txn_ref'));

        return response()->json(['ok' => true]);
    }

    public function balance(Request $request): JsonResponse
    {
        if ($rejected = $this->rejectUnlessCredentialed($request)) {
            return $rejected;
        }

        return response()->json(['balance' => LocalCoreStubState::balance((string) $request->query('user_ref'))]);
    }
}
