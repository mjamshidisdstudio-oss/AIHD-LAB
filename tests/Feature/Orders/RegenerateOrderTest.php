<?php

namespace Tests\Feature\Orders;

use App\Actions\Catalog\PublishVersion;
use App\Contracts\CoinService;
use App\Enums\ServiceInputType;
use App\Models\Order;
use App\Models\Service;
use App\Models\ServiceInput;
use App\Models\ServiceVersion;
use App\Services\Coins\MockCoinService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\ActsAsCoreUser;
use Tests\TestCase;

/**
 * "Run again" chains a new order onto a previous one's regeneration lineage
 * (root_order_id) instead of starting an unrelated order, capped by the
 * version's regenerate_limit.
 */
class RegenerateOrderTest extends TestCase
{
    use ActsAsCoreUser, RefreshDatabase;

    private function publishedService(int $regenerateLimit = 1): Service
    {
        $service = Service::factory()->create();
        $version = ServiceVersion::factory()->draft()->create([
            'service_id' => $service->id,
            'version_no' => 1,
            'coin_cost' => 0,
            'regenerate_limit' => $regenerateLimit,
        ]);
        ServiceInput::factory()->ofType(ServiceInputType::Text)->create([
            'service_version_id' => $version->id,
            'slug' => 'prompt',
        ]);
        app(PublishVersion::class)->handle($version);

        return $service->refresh();
    }

    private function submit(array $headers, string $serviceId, ?string $regenerateFrom = null): TestResponse
    {
        $payload = ['service_id' => $serviceId, 'answers' => ['prompt' => 'again']];
        if ($regenerateFrom !== null) {
            $payload['regenerated_from_order_id'] = $regenerateFrom;
        }

        return $this->withHeaders($headers)->postJson('/api/orders', $payload);
    }

    public function test_regenerating_chains_root_order_id_and_is_capped_by_regenerate_limit(): void
    {
        Queue::fake();
        $this->app->instance(CoinService::class, new MockCoinService);
        $service = $this->publishedService(regenerateLimit: 1);
        $headers = $this->coreUserHeaders('user-1');

        $root = $this->submit($headers, $service->id)->assertStatus(202)->json('data');
        $this->assertNull($root['root_order_id']);

        $regen = $this->submit($headers, $service->id, $root['id'])->assertStatus(202)->json('data');
        $this->assertSame($root['id'], $regen['root_order_id']);
        $this->assertSame($root['id'], $regen['regenerated_from_order_id']);

        // Limit is 1 and one regeneration already exists off this root — the next is rejected.
        $this->submit($headers, $service->id, $regen['id'])->assertStatus(422);
        $this->assertSame(2, Order::count());
    }

    public function test_a_user_cannot_regenerate_from_another_users_order(): void
    {
        Queue::fake();
        $this->app->instance(CoinService::class, new MockCoinService);
        $service = $this->publishedService();

        $root = $this->submit($this->coreUserHeaders('user-1'), $service->id)->assertStatus(202)->json('data');

        $this->submit($this->coreUserHeaders('user-2'), $service->id, $root['id'])->assertForbidden();
    }

    public function test_regenerated_from_order_id_must_belong_to_the_same_service(): void
    {
        Queue::fake();
        $this->app->instance(CoinService::class, new MockCoinService);
        $headers = $this->coreUserHeaders('user-1');
        $serviceA = $this->publishedService();
        $serviceB = $this->publishedService();

        $root = $this->submit($headers, $serviceA->id)->assertStatus(202)->json('data');

        $this->submit($headers, $serviceB->id, $root['id'])->assertStatus(422);
    }
}
