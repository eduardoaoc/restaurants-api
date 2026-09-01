<?php

namespace Database\Factories;

use App\Models\Restaurant;
use App\Models\Table;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Table>
 */
class TableFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'restaurant_id' => Restaurant::factory(),
            'name' => 'Table '.fake()->unique()->numberBetween(1, 9999),
            'number' => fake()->numberBetween(1, 9999),
            'public_token' => Table::generateUniquePublicToken(),
            'status' => 'active',
        ];
    }
}
