<?php

namespace Database\Factories;

use App\Enums\ServiceVersionStatus;
use App\Models\Service;
use App\Models\ServiceVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ServiceVersion>
 */
class ServiceVersionFactory extends Factory
{
    protected $model = ServiceVersion::class;

    public function definition(): array
    {
        return [
            'service_id' => Service::factory(),
            'version_no' => $this->faker->unique()->numberBetween(1, 1_000_000),
            'status' => ServiceVersionStatus::Published,
            'coin_cost' => $this->faker->numberBetween(0, 10),
            'regenerate_limit' => $this->faker->numberBetween(0, 5),
            'response_timeout_s' => 120,
            'get_interval_s' => 30,
            'max_get_attempts' => 10,
            'post_url' => $this->faker->url(),
            'get_url' => $this->faker->url(),
            'published_at' => now(),
        ];
    }

    public function draft(): static
    {
        return $this->state(fn () => [
            'status' => ServiceVersionStatus::Draft,
            'published_at' => null,
        ]);
    }

    public function retired(): static
    {
        return $this->state(fn () => ['status' => ServiceVersionStatus::Retired]);
    }
}
