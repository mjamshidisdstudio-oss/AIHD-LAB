<?php

namespace App\Support\Core;

use App\Exceptions\Core\CoreServiceUnavailableException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Thin shared HTTP transport to the core service, used by both CoreCoinService
 * and CoreTokenAuthenticator. It only handles what both share: base URL,
 * service-credential auth, timeouts, and translating connection failures into
 * CoreServiceUnavailableException. Response status codes (401/402/404) carry
 * domain meaning specific to each caller, so callers inspect the response
 * themselves rather than this client guessing.
 */
class CoreApiClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $credential,
        private readonly int $timeoutSeconds,
        private readonly int $connectTimeoutSeconds,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws CoreServiceUnavailableException
     */
    public function post(string $path, array $payload, string $operation): Response
    {
        return $this->send($operation, fn (PendingRequest $client) => $client->post($path, $payload));
    }

    /**
     * @param  array<string, mixed>  $query
     *
     * @throws CoreServiceUnavailableException
     */
    public function get(string $path, array $query, string $operation): Response
    {
        return $this->send($operation, fn (PendingRequest $client) => $client->get($path, $query));
    }

    /**
     * @throws CoreServiceUnavailableException
     */
    private function send(string $operation, callable $call): Response
    {
        try {
            return $call(
                Http::baseUrl($this->baseUrl)
                    ->withToken($this->credential)
                    ->asJson()
                    ->acceptJson()
                    ->timeout($this->timeoutSeconds)
                    ->connectTimeout($this->connectTimeoutSeconds)
            );
        } catch (ConnectionException $e) {
            throw CoreServiceUnavailableException::unreachable($operation, $e);
        }
    }
}
