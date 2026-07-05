<?php

namespace Database\Factories;

use App\Models\ServiceVersion;
use App\Models\ServiceWaitingText;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ServiceWaitingText>
 */
class ServiceWaitingTextFactory extends Factory
{
    protected $model = ServiceWaitingText::class;

    public function definition(): array
    {
        return [
            'service_version_id' => ServiceVersion::factory(),
            'text' => $this->faker->sentence(),
            'sort_order' => $this->faker->numberBetween(0, 20),
        ];
    }
}
