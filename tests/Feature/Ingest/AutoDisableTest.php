<?php

namespace Tests\Feature\Ingest;

use App\Contracts\CoinService;
use App\Enums\FailureStage;
use App\Enums\ServiceStatus;
use App\Models\Service;
use App\Services\Coins\MockCoinService;
use App\Services\Ingest\FailRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsIngestFixtures;
use Tests\TestCase;

/**
 * A service that fails three times in a row is automatically taken out of
 * rotation — not on the first or second failure, and it does not need a
 * fourth to confirm it stays disabled.
 */
class AutoDisableTest extends TestCase
{
    use BuildsIngestFixtures, RefreshDatabase;

    public function test_third_consecutive_failure_auto_disables_service(): void
    {
        $this->app->instance(CoinService::class, new MockCoinService);
        $service = Service::factory()->create(['webhook_signing_key' => 'auto-disable-key']);
        $fail = new FailRequest(app(CoinService::class));

        // Failures 1 and 2: still active.
        for ($i = 0; $i < 2; $i++) {
            ['request' => $request] = $this->ingestFixture(service: $service);
            $fail->handle($request, FailureStage::Service);
        }
        $service->refresh();
        $this->assertSame(2, $service->consecutive_failures);
        $this->assertSame(ServiceStatus::Active, $service->status);

        // Failure 3: auto-disabled.
        ['request' => $thirdRequest] = $this->ingestFixture(service: $service);
        $fail->handle($thirdRequest, FailureStage::Service);

        $service->refresh();
        $this->assertSame(3, $service->consecutive_failures);
        $this->assertSame(ServiceStatus::AutoDisabled, $service->status);
    }
}
