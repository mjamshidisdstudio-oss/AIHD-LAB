<?php

namespace Database\Factories;

use App\Enums\ServiceKind;
use App\Enums\ServiceStatus;
use App\Models\Service;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Service>
 */
class ServiceFactory extends Factory
{
    protected $model = Service::class;

    public function definition(): array
    {
        $name = ucfirst($this->faker->unique()->words(2, true));

        return [
            'slug' => Str::slug($name).'-'.$this->faker->unique()->numberBetween(1, 1_000_000),
            'name' => $name,
            'description' => $this->faker->sentence(),
            'image_url' => $this->faker->imageUrl(),
            'kind' => ServiceKind::Internal,
            'external_url' => null,
            'category' => $this->faker->randomElement(['interior', 'portrait', 'restyle', 'upscale']),
            'service_secret' => Str::random(40),
            'status' => ServiceStatus::Active,
            'consecutive_failures' => 0,
            'current_version_id' => null,
            'vote_up' => 0,
            'vote_down' => 0,
            'avg_latency_ms' => 0,
            'trending_rank' => null,
        ];
    }

    public function external(): static
    {
        return $this->state(fn () => [
            'kind' => ServiceKind::External,
            'external_url' => $this->faker->url(),
        ]);
    }

    public function paused(): static
    {
        return $this->state(fn () => ['status' => ServiceStatus::Paused]);
    }

    public function autoDisabled(): static
    {
        return $this->state(fn () => [
            'status' => ServiceStatus::AutoDisabled,
            'consecutive_failures' => $this->faker->numberBetween(5, 20),
        ]);
    }
}
