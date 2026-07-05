<?php

namespace Database\Factories;

use App\Enums\ServiceInputType;
use App\Models\ServiceInput;
use App\Models\ServiceVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ServiceInput>
 */
class ServiceInputFactory extends Factory
{
    protected $model = ServiceInput::class;

    public function definition(): array
    {
        return [
            'service_version_id' => ServiceVersion::factory(),
            'slug' => $this->faker->unique()->slug(2),
            'title' => $this->faker->words(2, true),
            'type' => ServiceInputType::Text,
            'required' => $this->faker->boolean(),
            'multi_select' => false,
            'searchable' => false,
            'depends_on_input_id' => null,
            'depends_on_value' => null,
            'sort_order' => $this->faker->numberBetween(0, 20),
            'config' => null,
        ];
    }

    public function ofType(ServiceInputType $type): static
    {
        return $this->state(fn () => ['type' => $type]);
    }

    public function required(): static
    {
        return $this->state(fn () => ['required' => true]);
    }
}
