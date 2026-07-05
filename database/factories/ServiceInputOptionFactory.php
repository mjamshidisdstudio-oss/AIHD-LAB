<?php

namespace Database\Factories;

use App\Models\ServiceInput;
use App\Models\ServiceInputOption;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ServiceInputOption>
 */
class ServiceInputOptionFactory extends Factory
{
    protected $model = ServiceInputOption::class;

    public function definition(): array
    {
        return [
            'input_id' => ServiceInput::factory(),
            'slug' => $this->faker->unique()->slug(2),
            'label' => $this->faker->words(2, true),
            'color' => $this->faker->safeHexColor(),
            'icon' => $this->faker->randomElement(['star', 'bolt', 'leaf', 'flame', 'droplet']),
            'sort_order' => $this->faker->numberBetween(0, 20),
        ];
    }
}
