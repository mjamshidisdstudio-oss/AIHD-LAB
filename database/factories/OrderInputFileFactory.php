<?php

namespace Database\Factories;

use App\Models\File;
use App\Models\OrderInput;
use App\Models\OrderInputFile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderInputFile>
 */
class OrderInputFileFactory extends Factory
{
    protected $model = OrderInputFile::class;

    public function definition(): array
    {
        return [
            'order_input_id' => OrderInput::factory(),
            'file_id' => File::factory(),
            'position' => $this->faker->numberBetween(0, 5),
        ];
    }
}
