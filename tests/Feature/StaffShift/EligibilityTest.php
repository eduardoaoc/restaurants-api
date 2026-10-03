<?php

namespace Tests\Feature\StaffShift;

use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bloco 3 — StaffShiftEligibility, exercised end-to-end through the start
 * endpoint. Every rejection here is a 422 — never 404/403, which are
 * reserved for the requester's own access, not the candidate's validity.
 */
class EligibilityTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    public function test_suspended_user_is_rejected(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');
        $waiter->update(['status' => User::STATUS_SUSPENDED]);

        $this->actingAs($owner, 'web')
            ->postJson("/api/v1/restaurants/{$restaurant->id}/staff-shifts", ['user_id' => $waiter->id])
            ->assertStatus(422);

        $this->assertDatabaseCount('staff_shifts', 0);
    }

    public function test_user_without_restaurant_membership_is_rejected(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $unaffiliated = User::factory()->create();
        $organization->users()->attach($unaffiliated->id);

        $this->actingAs($owner, 'web')
            ->postJson("/api/v1/restaurants/{$restaurant->id}/staff-shifts", ['user_id' => $unaffiliated->id])
            ->assertStatus(422);

        $this->assertDatabaseCount('staff_shifts', 0);
    }

    public function test_user_from_another_organization_is_rejected(): void
    {
        [$organizationA, $ownerA, $restaurantA] = $this->createTenant();
        [$organizationB, , $restaurantB] = $this->createTenant();
        $waiterB = $this->createStaff($organizationB, $restaurantB, 'waiter', 'W-B');

        $this->actingAs($ownerA, 'web')
            ->postJson("/api/v1/restaurants/{$restaurantA->id}/staff-shifts", ['user_id' => $waiterB->id])
            ->assertStatus(422);

        $this->assertDatabaseCount('staff_shifts', 0);
    }

    public function test_user_of_a_different_restaurant_of_the_same_organization_is_rejected(): void
    {
        [$organization, $owner, $restaurantA] = $this->createTenant();
        $restaurantB = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $waiterB = $this->createStaff($organization, $restaurantB, 'waiter', 'W-B');

        $this->actingAs($owner, 'web')
            ->postJson("/api/v1/restaurants/{$restaurantA->id}/staff-shifts", ['user_id' => $waiterB->id])
            ->assertStatus(422);

        $this->assertDatabaseCount('staff_shifts', 0);
    }

    public function test_plain_owner_is_not_eligible_for_a_shift(): void
    {
        [, $owner, $restaurant] = $this->createTenant();

        // The owner has no restaurant_users row anywhere (organization-wide
        // role) — not automatically "working" at any restaurant.
        $this->actingAs($owner, 'web')
            ->postJson("/api/v1/restaurants/{$restaurant->id}/staff-shifts", ['user_id' => $owner->id])
            ->assertStatus(422);

        $this->assertDatabaseCount('staff_shifts', 0);
    }

    public function test_eligible_manager_waiter_kitchen_and_cashier_are_accepted(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();

        foreach (['manager', 'waiter', 'kitchen', 'cashier'] as $i => $role) {
            $staff = $this->createStaff($organization, $restaurant, $role, "R-{$i}");

            $this->actingAs($owner, 'web')
                ->postJson("/api/v1/restaurants/{$restaurant->id}/staff-shifts", ['user_id' => $staff->id])
                ->assertCreated();
        }

        $this->assertDatabaseCount('staff_shifts', 4);
    }
}
