<?php

namespace Database\Factories;

use App\Enums\EntryMode;
use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Service;
use App\Models\ServiceVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    protected $model = Order::class;

    public function definition(): array
    {
        return [
            'user_ref' => $this->faker->uuid(),
            'service_id' => Service::factory(),
            // Keep the (version, service) pair consistent so the composite FK
            // to service_versions(id, service_id) is satisfied.
            'service_version_id' => fn (array $attributes) => ServiceVersion::factory()
                ->create(['service_id' => $attributes['service_id']])
                ->getKey(),
            'status' => OrderStatus::Processing,
            'source' => OrderSource::Site,
            'entry_mode' => EntryMode::Wizard,
            'coins_charged' => $this->faker->numberBetween(0, 10),
            'coin_txn_ref' => $this->faker->uuid(),
            'regenerated_from_order_id' => null,
            'root_order_id' => null,
            'completed_at' => null,
        ];
    }

    public function completed(): static
    {
        return $this->state(fn () => [
            'status' => OrderStatus::Completed,
            'completed_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn () => ['status' => OrderStatus::Failed]);
    }

    public function adminPreview(): static
    {
        return $this->state(fn () => ['source' => OrderSource::AdminPreview]);
    }
}
