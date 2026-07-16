<?php

namespace Tests\Concerns;

use App\Contracts\CoinService;
use App\Enums\OrderStatus;
use App\Enums\RequestStatus;
use App\Enums\ServiceOutputType;
use App\Models\Order;
use App\Models\Request;
use App\Models\Service;
use App\Models\ServiceOutput;
use App\Models\ServiceVersion;
use App\Services\Coins\MockCoinService;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * Builds a service/version/order/request ready for ingest tests, with a known
 * webhook_signing_key so HMAC signatures can be computed, and a declared
 * output count so completion counting can be exercised deliberately.
 */
trait BuildsIngestFixtures
{
    /**
     * @return array{service: Service, version: ServiceVersion, order: Order, request: Request}
     */
    protected function ingestFixture(
        int $declaredOutputs = 1,
        string $signingKey = 'test-signing-key',
        array $orderOverrides = [],
        array $versionOverrides = [],
        array $requestOverrides = [],
        ?Service $service = null,
    ): array {
        // A fast in-memory double by default — tests that care about coin call
        // discipline bind their own Mockery mock afterward, overriding this.
        $this->app->instance(CoinService::class, new MockCoinService);

        // Reusing a $service (e.g. across multiple orders for the same
        // service) still needs its OWN version — orders(service_version_id,
        // service_id) is a composite FK, so a version can't be swapped onto a
        // different service after the fact.
        $service ??= Service::factory()->create(['webhook_signing_key' => $signingKey]);
        $version = ServiceVersion::factory()->create(array_merge([
            'service_id' => $service->id,
            'get_interval_s' => 1,
            'max_get_attempts' => 5,
        ], $versionOverrides));

        for ($n = 1; $n <= $declaredOutputs; $n++) {
            ServiceOutput::factory()->create([
                'service_version_id' => $version->id,
                'result_number' => $n,
                'type' => ServiceOutputType::Text,
            ]);
        }

        $order = Order::factory()->create(array_merge([
            'service_id' => $service->id,
            'service_version_id' => $version->id,
            'status' => OrderStatus::Processing,
        ], $orderOverrides));

        $request = Request::factory()->create(array_merge([
            'order_id' => $order->id,
            'attempt_no' => 1,
            'status' => RequestStatus::Awaiting,
            'external_order_id' => (string) Str::uuid(),
            'get_poll_count' => 0,
        ], $requestOverrides));

        return compact('service', 'version', 'order', 'request');
    }

    /**
     * POST a raw, exact body string — bypassing postJson()'s json_encode() —
     * so tests can control the precise bytes an HMAC signs and send bodies
     * that are deliberately not valid JSON.
     */
    protected function postRaw(string $uri, string $rawBody, array $headers = []): TestResponse
    {
        $headers = array_merge([
            'CONTENT_LENGTH' => mb_strlen($rawBody, '8bit'),
            'CONTENT_TYPE' => 'application/json',
            'Accept' => 'application/json',
        ], $headers);

        return $this->call('POST', $uri, [], [], [], $this->transformHeadersToServerVars($headers), $rawBody);
    }

    protected function hmacFor(string $rawBody, string $signingKey): string
    {
        return hash_hmac('sha256', $rawBody, $signingKey);
    }
}
