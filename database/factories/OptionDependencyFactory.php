<?php

namespace Database\Factories;

use App\Models\OptionDependency;
use App\Models\ServiceInputOption;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OptionDependency>
 */
class OptionDependencyFactory extends Factory
{
    protected $model = OptionDependency::class;

    public function definition(): array
    {
        return [
            'option_id' => ServiceInputOption::factory(),
            'parent_option_id' => ServiceInputOption::factory(),
        ];
    }
}
