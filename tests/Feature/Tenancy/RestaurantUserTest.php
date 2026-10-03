<?php

namespace Tests\Feature\Tenancy;

use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RestaurantUserTest extends TestCase
{
    use RefreshDatabase;

    public function test_employee_can_be_linked_to_restaurant(): void
    {
        $restaurant = Restaurant::factory()->create();
        $user = User::factory()->create();

        $restaurant->users()->attach($user, ['sub_id' => 'W1']);

        $this->assertTrue($user->fresh()->restaurants->contains($restaurant));
        $this->assertSame('W1', $user->fresh()->restaurants->first()->pivot->sub_id);
    }

    /**
     * Bloco 18: an operational user may belong to several restaurants of
     * the same organization at once (Carlos -> A + B) — see RestaurantScope
     * and CreateStaffAction/UpdateStaffAction's restaurant_assignments.
     */
    public function test_operational_user_can_belong_to_two_restaurants_simultaneously(): void
    {
        $restaurantA = Restaurant::factory()->create();
        $restaurantB = Restaurant::factory()->create();
        $user = User::factory()->create();

        $restaurantA->users()->attach($user, ['sub_id' => 'W1']);
        $restaurantB->users()->attach($user, ['sub_id' => 'W1']);

        $restaurantIds = $user->fresh()->restaurants->pluck('id')->sort()->values();
        $this->assertSame([$restaurantA->id, $restaurantB->id], $restaurantIds->sort()->values()->all());
    }

    /**
     * The new (user_id, restaurant_id) unique constraint still forbids the
     * exact same link twice.
     */
    public function test_the_same_restaurant_cannot_be_linked_to_the_same_user_twice(): void
    {
        $restaurant = Restaurant::factory()->create();
        $user = User::factory()->create();

        $restaurant->users()->attach($user, ['sub_id' => 'W1']);

        $this->expectException(QueryException::class);

        $restaurant->users()->attach($user, ['sub_id' => 'W2']);
    }

    public function test_sub_id_cannot_repeat_within_the_same_restaurant(): void
    {
        $restaurant = Restaurant::factory()->create();
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $restaurant->users()->attach($userA, ['sub_id' => 'W1']);

        $this->expectException(QueryException::class);

        $restaurant->users()->attach($userB, ['sub_id' => 'W1']);
    }
}
