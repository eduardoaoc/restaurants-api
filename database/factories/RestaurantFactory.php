<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\Restaurant;
use App\Models\RestaurantSettings;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Restaurant>
 */
class RestaurantFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->company().' Restaurant';

        return [
            'organization_id' => Organization::factory(),
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numerify('####'),
            'status' => 'active',
        ];
    }

    /**
     * Every real Restaurant is created with a settings row in the same
     * transaction (see RestaurantController::store()) — the factory
     * mirrors that so `$restaurant->settings` is never null in tests
     * either, exactly like a real Restaurant.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (Restaurant $restaurant) {
            if (! $restaurant->settings) {
                RestaurantSettings::createDefaultsFor($restaurant);
            }
        });
    }
}
