<?php

namespace Tests\Unit\Services;

use App\Exceptions\External\ExternalServiceReportedFailureException;
use App\Models\Service;
use App\Models\ServiceVersion;
use App\Services\External\HttpExternalServiceClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Never Again: submit()/poll() sent no Authorization header at all -- a real
 * external provider has no way to tell our calls apart from anyone else's.
 * Every other leg of this integration (webhook HMAC, storage Bearer auth)
 * already treats webhook_signing_key as the shared secret with the provider;
 * this reuses it for the outbound leg too, rather than leaving it unauthenticated.
 */
class HttpExternalServiceClientTest extends TestCase
{
    use RefreshDatabase;

    public function test_submit_sends_the_webhook_signing_key_as_a_bearer_token(): void
    {
        Http::fake(['*' => Http::response(['external_order_id' => 'ext-1'])]);

        $service = Service::factory()->create(['webhook_signing_key' => 'shared-secret-123']);
        $version = ServiceVersion::factory()->create([
            'service_id' => $service->id,
            'post_url' => 'https://provider.test/run',
        ]);

        (new HttpExternalServiceClient)->submit($version, ['order_id' => 'o-1']);

        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer shared-secret-123'));
    }

    public function test_poll_sends_the_webhook_signing_key_as_a_bearer_token(): void
    {
        Http::fake(['*' => Http::response(['status' => 'pending'])]);

        $service = Service::factory()->create(['webhook_signing_key' => 'shared-secret-456']);
        $version = ServiceVersion::factory()->create([
            'service_id' => $service->id,
            'get_url' => 'https://provider.test/jobs',
        ]);

        (new HttpExternalServiceClient)->poll($version, 'ext-1');

        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer shared-secret-456'));
    }

    /**
     * Never Again: poll() treated ANY non-"completed" status identically to
     * "still pending" -- including a provider explicitly reporting failure.
     * FailureStage::Service could never actually be reached because nothing
     * ever distinguished "failed" from "not done yet".
     */
    public function test_poll_throws_a_distinguishable_exception_when_the_provider_reports_failure(): void
    {
        Http::fake(['*' => Http::response(['status' => 'failed', 'reason' => 'model unavailable'])]);

        $service = Service::factory()->create(['webhook_signing_key' => 'k']);
        $version = ServiceVersion::factory()->create(['service_id' => $service->id, 'get_url' => 'https://provider.test/jobs']);

        $this->expectException(ExternalServiceReportedFailureException::class);

        (new HttpExternalServiceClient)->poll($version, 'ext-1');
    }

    public function test_poll_still_treats_a_merely_pending_status_as_null(): void
    {
        Http::fake(['*' => Http::response(['status' => 'processing'])]);

        $service = Service::factory()->create(['webhook_signing_key' => 'k']);
        $version = ServiceVersion::factory()->create(['service_id' => $service->id, 'get_url' => 'https://provider.test/jobs']);

        $this->assertNull((new HttpExternalServiceClient)->poll($version, 'ext-1'));
    }
}
