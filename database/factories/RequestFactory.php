<?php

namespace Database\Factories;

use App\Enums\FailureStage;
use App\Enums\RequestStatus;
use App\Models\Order;
use App\Models\Request;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Request>
 */
class RequestFactory extends Factory
{
    protected $model = Request::class;

    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'attempt_no' => $this->faker->unique()->numberBetween(1, 1_000_000),
            'external_order_id' => $this->faker->uuid(),
            'status' => RequestStatus::Completed,
            'failure_stage' => null,
            'sent_at' => now(),
            'last_polled_at' => now(),
            'get_poll_count' => $this->faker->numberBetween(0, 10),
        ];
    }

    public function failed(FailureStage $stage = FailureStage::Service): static
    {
        return $this->state(fn () => [
            'status' => RequestStatus::Failed,
            'failure_stage' => $stage,
        ]);
    }
}
