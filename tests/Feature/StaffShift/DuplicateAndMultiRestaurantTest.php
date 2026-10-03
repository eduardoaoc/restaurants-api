<?php

namespace Tests\Feature\StaffShift;

use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithStaffShifts;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bloco 3 — one active shift per (user, restaurant), and multi-restaurant
 * independence (Bloco 18: the same user can be active at A and B at once).
 */
class DuplicateAndMultiRestaurantTest extends TestCase
{
    use InteractsWithStaffShifts, InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    public function test_cannot_start_a_second_active_shift_for_the_same_user_and_restaurant(): void
    {
        [$organization, , $restaurant] = $this->createTenant();
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');
        $this->startShift($restaurant, $waiter, $waiter);

        $this->actingAs($waiter, 'web')
            ->postJson("/api/v1/restaurants/{$restaurant->id}/staff-shifts", ['user_id' => $waiter->id])
            ->assertStatus(409);

        $this->assertDatabaseCount('staff_shifts', 1);
    }

    public function test_starting_again_after_ending_is_allowed(): void
    {
        [$organization, , $restaurant] = $this->createTenant();
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');
        $firstShift = $this->startShift($restaurant, $waiter, $waiter);
        $this->endShift($firstShift, $waiter);

        $this->actingAs($waiter, 'web')
            ->postJson("/api/v1/restaurants/{$restaurant->id}/staff-shifts", ['user_id' => $waiter->id])
            ->assertCreated();

        $this->assertDatabaseCount('staff_shifts', 2);
    }

    public function test_same_user_can_be_active_at_two_restaurants_simultaneously(): void
    {
        [$organization, $owner, $restaurantA] = $this->createTenant();
        $restaurantB = Restaurant::factory()->create(['organization_id' => $organization->id]);

        $multiWaiter = $this->createStaffAcrossRestaurants($organization, [$restaurantA, $restaurantB], 'waiter', $owner);

        $this->actingAs($multiWaiter, 'web')
            ->postJson("/api/v1/restaurants/{$restaurantA->id}/staff-shifts", ['user_id' => $multiWaiter->id])
            ->assertCreated();

        $this->actingAs($multiWaiter, 'web')
            ->postJson("/api/v1/restaurants/{$restaurantB->id}/staff-shifts", ['user_id' => $multiWaiter->id])
            ->assertCreated();

        $this->assertDatabaseHas('staff_shifts', ['restaurant_id' => $restaurantA->id, 'user_id' => $multiWaiter->id, 'ended_at' => null]);
        $this->assertDatabaseHas('staff_shifts', ['restaurant_id' => $restaurantB->id, 'user_id' => $multiWaiter->id, 'ended_at' => null]);
        $this->assertDatabaseCount('staff_shifts', 2);
    }

    public function test_ending_the_shift_at_one_restaurant_leaves_the_other_active(): void
    {
        [$organization, $owner, $restaurantA] = $this->createTenant();
        $restaurantB = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $multiWaiter = $this->createStaffAcrossRestaurants($organization, [$restaurantA, $restaurantB], 'waiter', $owner);

        $shiftA = $this->startShift($restaurantA, $multiWaiter, $multiWaiter);
        $shiftB = $this->startShift($restaurantB, $multiWaiter, $multiWaiter);

        $this->actingAs($multiWaiter, 'web')
            ->postJson("/api/v1/staff-shifts/{$shiftA->id}/end")
            ->assertOk();

        $this->assertNotNull($shiftA->fresh()->ended_at);
        $this->assertNull($shiftB->fresh()->ended_at);
    }
}
