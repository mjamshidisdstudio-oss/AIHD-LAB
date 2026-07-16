<?php

namespace Tests\Unit\Services;

use App\Exceptions\Coins\InsufficientCoinsException;
use App\Exceptions\Core\CoreServiceUnavailableException;
use App\Services\Coins\CoreCoinService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The real CoinService implementation's HTTP-facing behavior in isolation:
 * request shapes, response parsing, and translating the core's status codes
 * into our domain exceptions. Coin CALL-DISCIPLINE (exactly-once deduct/
 * settle/refund) is covered separately by SubmitOrderTest/CoinLifecycleTest,
 * which mock this interface entirely.
 */
class CoreCoinServiceTest extends TestCase
{
    public function test_deduct_success_returns_the_txn_ref(): void
    {
        Http::fake(['*/coins/deduct' => Http::response(['txn_ref' => 'txn-1'])]);

        $txnRef = app(CoreCoinService::class)->deduct('user-1', 5, 'key-1');

        $this->assertSame('txn-1', $txnRef);
        Http::assertSent(fn ($request) => $request->url() === config('core.base_url').'/coins/deduct'
            && $request['user_ref'] === 'user-1'
            && $request['amount'] === 5
            && $request['idempotency_key'] === 'key-1'
            && $request->hasHeader('Authorization', 'Bearer '.config('core.credential')));
    }

    public function test_deduct_402_raises_insufficient_coins_exception(): void
    {
        Http::fake(['*/coins/deduct' => Http::response(['message' => 'no funds'], 402)]);

        $this->expectException(InsufficientCoinsException::class);

        app(CoreCoinService::class)->deduct('user-1', 5, 'key-1');
    }

    public function test_deduct_unreachable_raises_core_service_unavailable(): void
    {
        Http::fake(['*/coins/deduct' => fn () => throw new ConnectionException('timed out')]);

        $this->expectException(CoreServiceUnavailableException::class);

        app(CoreCoinService::class)->deduct('user-1', 5, 'key-1');
    }

    public function test_settle_posts_the_txn_ref(): void
    {
        Http::fake(['*/coins/settle' => Http::response(['ok' => true])]);

        app(CoreCoinService::class)->settle('txn-1');

        Http::assertSent(fn ($request) => $request->url() === config('core.base_url').'/coins/settle'
            && $request['txn_ref'] === 'txn-1');
    }

    public function test_refund_posts_the_txn_ref(): void
    {
        Http::fake(['*/coins/refund' => Http::response(['ok' => true])]);

        app(CoreCoinService::class)->refund('txn-1');

        Http::assertSent(fn ($request) => $request->url() === config('core.base_url').'/coins/refund'
            && $request['txn_ref'] === 'txn-1');
    }

    public function test_balance_returns_the_int_from_the_response(): void
    {
        Http::fake(['*/coins/balance*' => Http::response(['balance' => 42])]);

        $this->assertSame(42, app(CoreCoinService::class)->balance('user-1'));
    }
}
