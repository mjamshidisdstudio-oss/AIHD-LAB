<?php

namespace Tests\Feature\Orders;

use App\Actions\Catalog\PublishVersion;
use App\Contracts\CoinService;
use App\Enums\ServiceInputType;
use App\Jobs\DispatchRequest;
use App\Jobs\PollRequestResult;
use App\Models\Service;
use App\Models\ServiceInput;
use App\Models\ServiceVersion;
use App\Services\Coins\MockCoinService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\ActsAsCoreUser;
use Tests\TestCase;

/**
 * Never Again: a burst against POST /api/orders must be rate-limited, not
 * left to hammer the coin service and dispatch real external work
 * unbounded. The limiter is keyed by the core-identity user_ref (see
 * AppServiceProvider::registerRateLimiters), not just IP -- proven here by
 * checking two different users each get their own, independent budget.
 */
class RateLimitTest extends TestCase
{
    use ActsAsCoreUser, RefreshDatabase;

    private function publishedService(): Service
    {
        $service = Service::factory()->create();
        $version = ServiceVersion::factory()->draft()->create([
            'service_id' => $service->id,
            'version_no' => 1,
            'coin_cost' => 0,
        ]);
        ServiceInput::factory()->ofType(ServiceInputType::Text)->create([
            'service_version_id' => $version->id,
            'slug' => 'prompt',
        ]);
        app(PublishVersion::class)->handle($version);

        return $service->refresh();
    }

    public function test_rate_limit_blocks_burst_on_submit(): void
    {
        Queue::fake([DispatchRequest::class, PollRequestResult::class]);
        $this->app->instance(CoinService::class, new MockCoinService);

        $service = $this->publishedService();
        $headers = $this->coreUserHeaders('burst-user');

        // The limit is 10/minute (registerRateLimiters) -- the first 10 pass.
        for ($i = 0; $i < 10; $i++) {
            $this->withHeaders($headers)->postJson('/api/orders', [
                'service_id' => $service->id,
                'answers' => ['prompt' => "attempt {$i}"],
            ])->assertStatus(202);
        }

        // The 11th in the same window is throttled, not processed.
        $this->withHeaders($headers)->postJson('/api/orders', [
            'service_id' => $service->id,
            'answers' => ['prompt' => 'one too many'],
        ])->assertStatus(429);
    }

    public function test_rate_limit_is_scoped_per_user_not_shared_by_ip(): void
    {
        Queue::fake([DispatchRequest::class, PollRequestResult::class]);
        $this->app->instance(CoinService::class, new MockCoinService);

        $service = $this->publishedService();

        // User A spends its whole budget.
        $userA = $this->coreUserHeaders('user-a');
        for ($i = 0; $i < 10; $i++) {
            $this->withHeaders($userA)->postJson('/api/orders', [
                'service_id' => $service->id,
                'answers' => ['prompt' => "a-{$i}"],
            ])->assertStatus(202);
        }
        $this->withHeaders($userA)->postJson('/api/orders', [
            'service_id' => $service->id,
            'answers' => ['prompt' => 'a-over'],
        ])->assertStatus(429);

        // User B (same test client / IP) still has its own fresh budget --
        // proves the limiter key is userRef(), not the shared request IP.
        $userB = $this->coreUserHeaders('user-b');
        $this->withHeaders($userB)->postJson('/api/orders', [
            'service_id' => $service->id,
            'answers' => ['prompt' => 'b-1'],
        ])->assertStatus(202);
    }
}
