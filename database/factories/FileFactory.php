<?php

namespace Database\Factories;

use App\Enums\FileKind;
use App\Models\File;
use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<File>
 */
class FileFactory extends Factory
{
    protected $model = File::class;

    public function definition(): array
    {
        $kind = $this->faker->randomElement(FileKind::cases());

        return [
            'kind' => $kind,
            'disk' => 'media',
            'order_id' => Order::factory(),
            'mime' => 'image/png',
            'path' => $kind->value.'/'.Str::uuid().'.png',
            'size' => $this->faker->numberBetween(1_000, 8_000_000),
        ];
    }

    public function input(): static
    {
        return $this->state(fn () => ['kind' => FileKind::Input]);
    }

    public function result(): static
    {
        return $this->state(fn () => ['kind' => FileKind::Result]);
    }
}
