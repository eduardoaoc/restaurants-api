<?php

namespace Tests\Feature\StaffShift;

use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithStaffShifts;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bloco 3 — who may start/end a shift: self always, otherwise
 * manage_staff_shifts (owner/manager by default). Order: cross tenant /
 * outside RestaurantScope -> 404, in scope without permission -> 403,
 * unauthenticated -> 401.
 */
class AuthorizationTest extends TestCase
{
    use InteractsWithStaffShifts, InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    public function test_self_start_is_allowed(): void
    {
        [$organization, , $restaurant] = $this->createTenant();
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');

        $this->actingAs($waiter, 'web')
            ->postJson("/api/v1/restaurants/{$restaurant->id}/staff-shifts", ['user_id' => $waiter->id])
            ->assertCreated();
    }

    public function test_self_end_is_allowed(): void
    {
        [$organization, , $restaurant] = $this->createTenant();
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');
        $shift = $this->startShift($restaurant, $waiter, $waiter);

        $this->actingAs($waiter, 'web')
            ->postJson("/api/v1/staff-shifts/{$shift->id}/end")
            ->assertOk();
    }

    public function test_staff_cannot_start_another_users_shift(): void
    {
        [$organization, , $restaurant] = $this->createTenant();
        $requester = $this->createStaff($organization, $restaurant, 'waiter', 'W-REQ');
        $target = $this->createStaff($organization, $restaurant, 'waiter', 'W-TGT');

        $this->actingAs($requester, 'web')
            ->postJson("/api/v1/restaurants/{$restaurant->id}/staff-shifts", ['user_id' => $target->id])
            ->assertForbidden();

        $this->assertDatabaseCount('staff_shifts', 0);
    }

    public function test_staff_cannot_end_another_users_shift(): void
    {
        [$organization, , $restaurant] = $this->createTenant();
        $requester = $this->createStaff($organization, $restaurant, 'waiter', 'W-REQ');
        $target = $this->createStaff($organization, $restaurant, 'waiter', 'W-TGT');
        $shift = $this->startShift($restaurant, $target, $target);

        $this->actingAs($requester, 'web')
            ->postJson("/api/v1/staff-shifts/{$shift->id}/end")
            ->assertForbidden();

        $this->assertNull($shift->fresh()->ended_at);
    }

    public function test_manager_with_permission_can_start_and_end_others_shifts(): void
    {
        [$organization, , $restaurant] = $this->createTenant();
        $manager = $this->createStaff($organization, $restaurant, 'manager', 'M-1');
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');

        $response = $this->actingAs($manager, 'web')
            ->postJson("/api/v1/restaurants/{$restaurant->id}/staff-shifts", ['user_id' => $waiter->id])
            ->assertCreated();

        $shiftId = $response->json('data.staff_shift.id');

        $this->actingAs($manager, 'web')
            ->postJson("/api/v1/staff-shifts/{$shiftId}/end")
            ->assertOk();
    }

    public function test_kitchen_cannot_start_another_users_shift(): void
    {
        [$organization, , $restaurant] = $this->createTenant();
        $kitchen = $this->createStaff($organization, $restaurant, 'kitchen', 'K-1');
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');

        $this->actingAs($kitchen, 'web')
            ->postJson("/api/v1/restaurants/{$restaurant->id}/staff-shifts", ['user_id' => $waiter->id])
            ->assertForbidden();
    }

    public function test_manager_outside_restaurant_scope_gets_not_found_starting_a_shift(): void
    {
        [$organization, , $restaurantA] = $this->createTenant();
        $restaurantB = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $managerB = $this->createStaff($organization, $restaurantB, 'manager', 'M-B');
        $waiterA = $this->createStaff($organization, $restaurantA, 'waiter', 'W-A');

        $this->actingAs($managerB, 'web')
            ->postJson("/api/v1/restaurants/{$restaurantA->id}/staff-shifts", ['user_id' => $waiterA->id])
            ->assertNotFound();
    }

    public function test_waiter_cannot_self_start_at_a_restaurant_outside_their_own_scope(): void
    {
        [$organization, , $restaurantA] = $this->createTenant();
        $restaurantB = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $waiterA = $this->createStaff($organization, $restaurantA, 'waiter', 'W-A');

        $this->actingAs($waiterA, 'web')
            ->postJson("/api/v1/restaurants/{$restaurantB->id}/staff-shifts", ['user_id' => $waiterA->id])
            ->assertNotFound();
    }

    public function test_cross_tenant_restaurant_id_is_not_found(): void
    {
        [, $ownerA, $restaurantA] = $this->createTenant();
        [$organizationB, , $restaurantB] = $this->createTenant();
        $waiterB = $this->createStaff($organizationB, $restaurantB, 'waiter', 'W-B');

        // ownerA's own restaurant, but the candidate belongs to org B: 422
        // (candidate ineligible), not a scope failure.
        $this->actingAs($ownerA, 'web')
            ->postJson("/api/v1/restaurants/{$restaurantA->id}/staff-shifts", ['user_id' => $waiterB->id])
            ->assertStatus(422);

        // restaurantB's id is entirely outside ownerA's own organization:
        // 404, before eligibility is ever considered.
        $this->actingAs($ownerA, 'web')
            ->postJson("/api/v1/restaurants/{$restaurantB->id}/staff-shifts", ['user_id' => $waiterB->id])
            ->assertNotFound();
    }

    public function test_guest_is_unauthenticated(): void
    {
        [, , $restaurant] = $this->createTenant();

        $this->postJson("/api/v1/restaurants/{$restaurant->id}/staff-shifts", ['user_id' => 1])
            ->assertUnauthorized();
    }

    public function test_ending_a_shift_from_another_tenant_is_not_found(): void
    {
        [$organizationA, , $restaurantA] = $this->createTenant();
        $waiterA = $this->createStaff($organizationA, $restaurantA, 'waiter', 'W-A');
        $shift = $this->startShift($restaurantA, $waiterA, $waiterA);

        [, $ownerB] = $this->createTenant();

        $this->actingAs($ownerB, 'web')
            ->postJson("/api/v1/staff-shifts/{$shift->id}/end")
            ->assertNotFound();
    }
}
