<?php

namespace Database\Factories;

use App\Models\Bookmark;
use App\Models\Service;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Bookmark>
 */
class BookmarkFactory extends Factory
{
    protected $model = Bookmark::class;

    public function definition(): array
    {
        return [
            'service_id' => Service::factory(),
            'user_ref' => $this->faker->unique()->uuid(),
            'created_at' => now(),
        ];
    }
}
