<?php

namespace Database\Factories;

use App\Enums\ServiceInputType;
use App\Models\Order;
use App\Models\OrderInput;
use App\Models\ServiceInput;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderInput>
 */
class OrderInputFactory extends Factory
{
    protected $model = OrderInput::class;

    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'input_id' => ServiceInput::factory(),
            // Exactly one scalar slot populated (respects the CHECK constraint).
            'value_text' => $this->faker->words(3, true),
            'value_bool' => null,
        ];
    }

    public function boolean(bool $value = true): static
    {
        return $this->state(fn () => [
            'value_text' => null,
            'value_bool' => $value,
        ]);
    }

    /**
     * Non-value-bearing answer: its value lives in the options / files tables,
     * so both scalar columns stay null. Pointed at a select input so the row
     * satisfies the "zero scalars for non-value-bearing inputs" CHECK.
     */
    public function empty(): static
    {
        return $this->state(fn () => [
            'input_id' => ServiceInput::factory()->ofType(ServiceInputType::Select),
            'value_text' => null,
            'value_bool' => null,
        ]);
    }
}
