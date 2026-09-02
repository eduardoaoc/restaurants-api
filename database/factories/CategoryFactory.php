<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\Menu;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Category>
 */
class CategoryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'menu_id' => Menu::factory(),
            'slug' => Str::slug($name).'-'.fake()->unique()->numerify('####'),
            'sort_order' => 0,
            'status' => 'active',
        ];
    }
}
