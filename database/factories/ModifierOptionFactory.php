<?php

namespace Database\Factories;

use App\Models\ModifierGroup;
use App\Models\ModifierOption;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ModifierOption>
 */
class ModifierOptionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'modifier_group_id' => ModifierGroup::factory(),
            'internal_name' => 'Option '.fake()->unique()->numberBetween(1, 9999),
            'price_delta' => 0,
            'available' => true,
            'sort_order' => 0,
            'status' => 'active',
        ];
    }
}
