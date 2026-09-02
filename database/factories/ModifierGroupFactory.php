<?php

namespace Database\Factories;

use App\Models\ModifierGroup;
use App\Models\RestaurantProduct;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ModifierGroup>
 */
class ModifierGroupFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'restaurant_product_id' => RestaurantProduct::factory(),
            'internal_name' => 'Group '.fake()->unique()->numberBetween(1, 9999),
            'min_select' => 0,
            'max_select' => 1,
            'required' => false,
            'sort_order' => 0,
            'status' => 'active',
        ];
    }
}
