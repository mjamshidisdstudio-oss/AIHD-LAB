<?php

namespace Database\Factories;

use App\Models\OrderInput;
use App\Models\OrderInputOption;
use App\Models\ServiceInputOption;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderInputOption>
 */
class OrderInputOptionFactory extends Factory
{
    protected $model = OrderInputOption::class;

    public function definition(): array
    {
        return [
            'order_input_id' => OrderInput::factory(),
            'option_id' => ServiceInputOption::factory(),
        ];
    }
}
