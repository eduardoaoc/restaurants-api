<?php

namespace Tests\Feature\TableSession;

use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithOrders;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bloco 2 — WaiterAssignmentEligibility, exercised end-to-end through the
 * assign endpoint. Every rejection here is a 422 (the candidate user_id is
 * not a valid value for this operation) — never a 404/403, which are
 * reserved for the requester's own access to the session itself.
 */
class WaiterEligibilityTest extends TestCase
{
    use InteractsWithOrders, InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    public function test_user_from_another_organization_is_rejected(): void
    {
        [$organizationA, $ownerA, $restaurantA] = $this->createTenant();
        $table = $this->createTable($restaurantA);
        $session = $this->openSession($table, $ownerA);

        [$organizationB, , $restaurantB] = $this->createTenant();
        $waiterB = $this->createStaff($organizationB, $restaurantB, 'waiter', 'W-B');

        $this->actingAs($ownerA, 'web')
            ->putJson("/api/v1/table-sessions/{$session->id}/waiter", ['user_id' => $waiterB->id])
            ->assertStatus(422);

        $this->assertNull($session->fresh()->assigned_waiter_user_id);
    }

    public function test_user_without_restaurant_membership_is_rejected(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);

        $unaffiliated = User::factory()->create();
        $organization->users()->attach($unaffiliated->id);

        $this->actingAs($owner, 'web')
            ->putJson("/api/v1/table-sessions/{$session->id}/waiter", ['user_id' => $unaffiliated->id])
            ->assertStatus(422);

        $this->assertNull($session->fresh()->assigned_waiter_user_id);
    }

    public function test_user_from_a_different_restaurant_of_the_same_organization_is_rejected(): void
    {
        [$organization, $owner, $restaurantA] = $this->createTenant();
        $restaurantB = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $table = $this->createTable($restaurantA);
        $session = $this->openSession($table, $owner);

        $waiterB = $this->createStaff($organization, $restaurantB, 'waiter', 'W-B');

        $this->actingAs($owner, 'web')
            ->putJson("/api/v1/table-sessions/{$session->id}/waiter", ['user_id' => $waiterB->id])
            ->assertStatus(422);

        $this->assertNull($session->fresh()->assigned_waiter_user_id);
    }

    public function test_suspended_user_is_rejected(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);

        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');
        $waiter->update(['status' => User::STATUS_SUSPENDED]);

        $this->actingAs($owner, 'web')
            ->putJson("/api/v1/table-sessions/{$session->id}/waiter", ['user_id' => $waiter->id])
            ->assertStatus(422);

        $this->assertNull($session->fresh()->assigned_waiter_user_id);
    }

    public function test_user_without_the_waiter_role_at_the_restaurant_is_rejected(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);

        $kitchen = $this->createStaff($organization, $restaurant, 'kitchen', 'K-1');

        $this->actingAs($owner, 'web')
            ->putJson("/api/v1/table-sessions/{$session->id}/waiter", ['user_id' => $kitchen->id])
            ->assertStatus(422);

        $this->assertNull($session->fresh()->assigned_waiter_user_id);
    }

    public function test_owner_cannot_be_assigned_as_waiter_via_organization_wide_role_alone(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);

        // The owner has an organization-wide role (no restaurant_users row)
        // — RestaurantScope lets them REACH every restaurant, but that must
        // never make them ELIGIBLE to be selected as the waiter themselves.
        $this->actingAs($owner, 'web')
            ->putJson("/api/v1/table-sessions/{$session->id}/waiter", ['user_id' => $owner->id])
            ->assertStatus(422);

        $this->assertNull($session->fresh()->assigned_waiter_user_id);
    }

    public function test_eligible_waiter_of_the_same_restaurant_is_accepted(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);

        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');

        $this->actingAs($owner, 'web')
            ->putJson("/api/v1/table-sessions/{$session->id}/waiter", ['user_id' => $waiter->id])
            ->assertOk();
    }
}
