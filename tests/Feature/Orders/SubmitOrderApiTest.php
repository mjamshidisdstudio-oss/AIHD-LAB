<?php

namespace Tests\Feature\Orders;

use App\Actions\Catalog\PublishVersion;
use App\Contracts\CoinService;
use App\Contracts\TokenAuthenticator;
use App\Enums\ServiceInputType;
use App\Exceptions\Coins\InsufficientCoinsException;
use App\Models\Order;
use App\Models\Service;
use App\Models\ServiceInput;
use App\Models\ServiceVersion;
use App\Services\Auth\MockTokenAuthenticator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\Concerns\ActsAsCoreUser;
use Tests\TestCase;

/**
 * The three ways POST /api/orders must fail SAFELY, never charging on a guess:
 * the core rejects the balance (402), the bearer token is invalid (401, and no
 * coin call is ever attempted), or the core cannot be reached at all (503).
 * None of these may leave an order behind.
 */
class SubmitOrderApiTest extends TestCase
{
    use ActsAsCoreUser, RefreshDatabase;

    private function publishedService(): Service
    {
        $service = Service::factory()->create();
        $version = ServiceVersion::factory()->draft()->create([
            'service_id' => $service->id,
            'version_no' => 1,
            'coin_cost' => 2,
        ]);
        ServiceInput::factory()->ofType(ServiceInputType::Text)->create([
            'service_version_id' => $version->id,
            'slug' => 'prompt',
        ]);
        app(PublishVersion::class)->handle($version);

        return $service->refresh();
    }

    /**
     * Never Again: a core-reported insufficient balance must surface as 402
     * and leave zero orders — never a half-created order.
     */
    public function test_insufficient_balance_returns_402_and_writes_no_order(): void
    {
        $coins = Mockery::mock(CoinService::class);
        $coins->shouldReceive('deduct')->once()->andThrow(InsufficientCoinsException::for('user-1', 2));
        $this->app->instance(CoinService::class, $coins);

        $service = $this->publishedService();
        $headers = $this->coreUserHeaders('user-1');

        $this->withHeaders($headers)->postJson('/api/orders', [
            'service_id' => $service->id,
            'answers' => ['prompt' => 'a cosy cabin'],
        ])->assertStatus(402);

        $this->assertSame(0, Order::count());
    }

    /**
     * Never Again: an invalid/missing bearer token must be rejected at the
     * authentication boundary — before SubmitOrder, before any coin call, and
     * before an order is ever written.
     */
    public function test_invalid_token_is_rejected_401_and_no_coin_call_made(): void
    {
        $coins = Mockery::mock(CoinService::class);
        $coins->shouldNotReceive('deduct');
        $this->app->instance(CoinService::class, $coins);
        // An unrecognized token — isolates the middleware/authenticator
        // rejection from the real HTTP transport (covered separately by
        // CoreTokenAuthenticatorTest).
        $this->app->instance(TokenAuthenticator::class, new MockTokenAuthenticator);

        $service = $this->publishedService();

        $this->withHeaders(['Authorization' => 'Bearer this-token-was-never-registered'])
            ->postJson('/api/orders', [
                'service_id' => $service->id,
                'answers' => ['prompt' => 'a cosy cabin'],
            ])->assertStatus(401);

        $this->assertSame(0, Order::count());
        // Mockery verifies deduct(0) on tearDown.
    }

    /**
     * Never Again: if the core cannot be reached at all, submit must fail
     * safely (503) rather than silently skipping the charge or crashing with a
     * raw 500 — and, again, no order is left behind.
     */
    public function test_core_unreachable_fails_submit_without_charging(): void
    {
        // Real CoreCoinService (the default binding) — only its HTTP transport
        // is faked, so this exercises the actual connection-failure handling.
        Http::fake([
            '*/coins/deduct' => fn () => throw new ConnectionException('Connection timed out'),
        ]);

        $service = $this->publishedService();
        $headers = $this->coreUserHeaders('user-1');

        $this->withHeaders($headers)->postJson('/api/orders', [
            'service_id' => $service->id,
            'answers' => ['prompt' => 'a cosy cabin'],
        ])->assertStatus(503);

        $this->assertSame(0, Order::count());
    }
}
