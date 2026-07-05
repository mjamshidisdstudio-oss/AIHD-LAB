<?php

namespace Database\Factories;

use App\Enums\InteractionKind;
use App\Models\Interaction;
use App\Models\Service;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Interaction>
 */
class InteractionFactory extends Factory
{
    protected $model = Interaction::class;

    public function definition(): array
    {
        return [
            'kind' => InteractionKind::Download,
            'user_ref' => $this->faker->uuid(),
            'service_id' => Service::factory(),
            'order_id' => null,
            'result_id' => null,
            'created_at' => now(),
        ];
    }

    public function externalClick(): static
    {
        return $this->state(fn () => ['kind' => InteractionKind::ExternalClick]);
    }
}
