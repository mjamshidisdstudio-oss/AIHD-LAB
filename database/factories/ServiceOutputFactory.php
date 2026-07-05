<?php

namespace Database\Factories;

use App\Enums\ServiceOutputType;
use App\Models\ServiceOutput;
use App\Models\ServiceVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ServiceOutput>
 */
class ServiceOutputFactory extends Factory
{
    protected $model = ServiceOutput::class;

    public function definition(): array
    {
        return [
            'service_version_id' => ServiceVersion::factory(),
            'result_number' => $this->faker->unique()->numberBetween(1, 1_000_000),
            'type' => ServiceOutputType::Image,
        ];
    }
}
