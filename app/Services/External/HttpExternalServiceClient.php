<?php

namespace App\Services\External;

use App\Contracts\ExternalServiceClient;
use App\Models\ServiceVersion;
use App\Support\External\ExternalResult;
use App\Support\External\ExternalResultItem;
use Illuminate\Support\Facades\Http;

/**
 * Calls the service's external provider over HTTP. In development the seeded
 * service's post_url/get_url point at the in-app dev service (routes/dev.php);
 * in production they point at the real provider. The transport is identical.
 *
 * Authenticates as a Bearer token using webhook_signing_key -- the same shared
 * secret the provider already uses to authenticate its calls back into us
 * (storage fetch/upload, webhook HMAC), reused here for the outbound leg
 * rather than introducing a second shared secret.
 */
class HttpExternalServiceClient implements ExternalServiceClient
{
    public function submit(ServiceVersion $version, array $payload): string
    {
        $response = Http::asJson()
            ->acceptJson()
            ->withToken($version->service->webhook_signing_key ?? '')
            ->timeout($version->response_timeout_s)
            ->post($version->post_url, $payload)
            ->throw();

        return (string) $response->json('external_order_id');
    }

    public function poll(ServiceVersion $version, string $externalOrderId): ?ExternalResult
    {
        $response = Http::acceptJson()
            ->withToken($version->service->webhook_signing_key ?? '')
            ->timeout($version->response_timeout_s)
            ->get($version->get_url, ['external_order_id' => $externalOrderId])
            ->throw();

        if ($response->json('status') !== 'completed') {
            return null;
        }

        $items = collect($response->json('results', []))
            ->map(fn (array $row) => new ExternalResultItem(
                resultNumber: (int) $row['result_number'],
                type: (string) $row['type'],
                text: $row['text'] ?? null,
                mime: $row['mime'] ?? null,
                bytes: isset($row['content_base64'])
                    ? (base64_decode($row['content_base64'], true) ?: null)
                    : null,
            ))
            ->all();

        return new ExternalResult($items, (int) $response->json('latency_ms', 0));
    }
}
