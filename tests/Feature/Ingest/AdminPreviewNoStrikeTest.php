<?php

namespace Tests\Feature\Ingest;

use App\Contracts\CoinService;
use App\Enums\FailureStage;
use App\Enums\OrderSource;
use App\Enums\ServiceStatus;
use App\Models\Service;
use App\Services\Coins\MockCoinService;
use App\Services\Ingest\FailRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsIngestFixtures;
use Tests\TestCase;

/**
 * An admin-preview run failing repeatedly must never auto-disable the
 * service — an operator exercising a draft's live preview shouldn't be able
 * to trip the same strike counter that protects real customer traffic.
 */
class AdminPreviewNoStrikeTest extends TestCase
{
    use BuildsIngestFixtures, RefreshDatabase;

    public function test_admin_preview_failures_never_increment_strikes_or_auto_disable(): void
    {
        $this->app->instance(CoinService::class, new MockCoinService);
        $service = Service::factory()->create(['webhook_signing_key' => 'admin-preview-key']);
        $fail = new FailRequest(app(CoinService::class));

        for ($i = 0; $i < 5; $i++) {
            ['request' => $request] = $this->ingestFixture(
                service: $service,
                orderOverrides: ['source' => OrderSource::AdminPreview],
            );
            $fail->handle($request, FailureStage::Service);
        }

        $service->refresh();
        $this->assertSame(0, $service->consecutive_failures);
        $this->assertSame(ServiceStatus::Active, $service->status);
    }

    public function test_site_failures_still_count_after_admin_preview_failures(): void
    {
        $this->app->instance(CoinService::class, new MockCoinService);
        $service = Service::factory()->create(['webhook_signing_key' => 'mixed-key']);
        $fail = new FailRequest(app(CoinService::class));

        ['request' => $previewRequest] = $this->ingestFixture(
            service: $service,
            orderOverrides: ['source' => OrderSource::AdminPreview],
        );
        $fail->handle($previewRequest, FailureStage::Service);

        for ($i = 0; $i < 3; $i++) {
            ['request' => $request] = $this->ingestFixture(service: $service);
            $fail->handle($request, FailureStage::Service);
        }

        $service->refresh();
        $this->assertSame(3, $service->consecutive_failures);
        $this->assertSame(ServiceStatus::AutoDisabled, $service->status);
    }
}
