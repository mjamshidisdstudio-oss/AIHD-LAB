<?php

namespace Database\Factories;

use App\Models\Service;
use App\Models\ServiceVersion;
use App\Models\ServiceVote;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ServiceVote>
 */
class ServiceVoteFactory extends Factory
{
    protected $model = ServiceVote::class;

    public function definition(): array
    {
        return [
            'service_id' => Service::factory(),
            'service_version_id' => fn (array $attributes) => ServiceVersion::factory()
                ->create(['service_id' => $attributes['service_id']])
                ->getKey(),
            'user_ref' => $this->faker->unique()->uuid(),
            'value' => $this->faker->randomElement([1, -1]),
        ];
    }

    public function up(): static
    {
        return $this->state(fn () => ['value' => 1]);
    }

    public function down(): static
    {
        return $this->state(fn () => ['value' => -1]);
    }
}
