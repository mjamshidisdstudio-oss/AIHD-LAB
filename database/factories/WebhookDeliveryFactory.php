<?php

namespace Database\Factories;

use App\Enums\WebhookOutcome;
use App\Models\Service;
use App\Models\WebhookDelivery;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WebhookDelivery>
 */
class WebhookDeliveryFactory extends Factory
{
    protected $model = WebhookDelivery::class;

    public function definition(): array
    {
        return [
            'service_id' => Service::factory(),
            'request_id' => null,
            'external_order_id' => $this->faker->uuid(),
            'result_number' => null,
            'outcome' => WebhookOutcome::Ingested,
            'http_status' => 200,
            'raw_body' => json_encode(['status' => 'ok', 'id' => $this->faker->uuid()]),
            'received_at' => now(),
        ];
    }

    public function invalidSignature(): static
    {
        return $this->state(fn () => [
            'outcome' => WebhookOutcome::InvalidSignature,
            'http_status' => 401,
        ]);
    }
}
