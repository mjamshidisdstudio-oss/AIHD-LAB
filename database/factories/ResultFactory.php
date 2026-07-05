<?php

namespace Database\Factories;

use App\Enums\ResultSource;
use App\Enums\ResultType;
use App\Models\Request;
use App\Models\Result;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Result>
 */
class ResultFactory extends Factory
{
    protected $model = Result::class;

    public function definition(): array
    {
        return [
            'request_id' => Request::factory(),
            'result_number' => $this->faker->unique()->numberBetween(1, 1_000_000),
            'type' => ResultType::Image,
            'file_id' => null,
            'text_value' => null,
            'source' => ResultSource::Webhook,
            'latency_ms' => $this->faker->numberBetween(200, 8000),
            'received_at' => now(),
        ];
    }

    public function text(): static
    {
        return $this->state(fn () => [
            'type' => ResultType::Text,
            'text_value' => $this->faker->sentence(),
        ]);
    }

    public function polled(): static
    {
        return $this->state(fn () => ['source' => ResultSource::Poll]);
    }
}
