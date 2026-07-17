<?php

namespace Tests\Feature\Ingest;

use App\Contracts\CoinService;
use App\Enums\FailureStage;
use App\Enums\ResultSource;
use App\Enums\ServiceStatus;
use App\Models\Service;
use App\Services\Coins\MockCoinService;
use App\Services\Ingest\FailRequest;
use App\Services\Ingest\IngestResult;
use App\Support\External\ExternalResultItem;
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

    /**
     * A success between failures breaks the streak: two failures followed by
     * a completed order reset consecutive_failures to 0, so a THIRD failure
     * afterward is only the service's first failure of a new streak, not the
     * one that disables it.
     */
    public function test_success_resets_consecutive_failures_after_a_partial_streak(): void
    {
        $this->app->instance(CoinService::class, new MockCoinService);
        $service = Service::factory()->create(['webhook_signing_key' => 'auto-disable-reset-key']);
        $fail = new FailRequest(app(CoinService::class));

        // Two failures: not yet disabled.
        for ($i = 0; $i < 2; $i++) {
            ['request' => $request] = $this->ingestFixture(service: $service);
            $fail->handle($request, FailureStage::Service);
        }
        $this->assertSame(2, $service->refresh()->consecutive_failures);

        // A completed order resets the streak.
        ['request' => $successRequest] = $this->ingestFixture(service: $service);
        $ingest = new IngestResult(app(CoinService::class));
        $ingest->handle($successRequest, new ExternalResultItem(resultNumber: 1, type: 'text', text: 'ok'), ResultSource::Webhook, 100);
        $this->assertSame(0, $service->refresh()->consecutive_failures);
        $this->assertSame(ServiceStatus::Active, $service->status);

        // One more failure is only strike 1 of a new streak -- not disabled.
        ['request' => $newStreakRequest] = $this->ingestFixture(service: $service);
        $fail->handle($newStreakRequest, FailureStage::Service);

        $service->refresh();
        $this->assertSame(1, $service->consecutive_failures);
        $this->assertSame(ServiceStatus::Active, $service->status);
    }
}
