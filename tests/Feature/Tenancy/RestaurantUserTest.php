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

    public function test_operational_user_cannot_belong_to_two_restaurants_simultaneously(): void
    {
        $restaurantA = Restaurant::factory()->create();
        $restaurantB = Restaurant::factory()->create();
        $user = User::factory()->create();

        $restaurantA->users()->attach($user, ['sub_id' => 'W1']);

        $this->expectException(QueryException::class);

        $restaurantB->users()->attach($user, ['sub_id' => 'W1']);
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
