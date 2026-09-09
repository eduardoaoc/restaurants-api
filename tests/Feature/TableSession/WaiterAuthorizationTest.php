<?php

namespace Tests\Feature\TableSession;

use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithOrders;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bloco 2 — who may ASSIGN a waiter (assign_waiters permission), as
 * distinct from who may BE ASSIGNED (WaiterEligibilityTest). Follows the
 * project-wide order: cross tenant / outside RestaurantScope -> 404,
 * visible but no permission -> 403, unauthenticated -> 401.
 */
class WaiterAuthorizationTest extends TestCase
{
    use InteractsWithOrders, InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    public function test_owner_can_assign_and_unassign(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');

        $this->actingAs($owner, 'web')
            ->putJson("/api/v1/table-sessions/{$session->id}/waiter", ['user_id' => $waiter->id])
            ->assertOk();

        $this->actingAs($owner, 'web')
            ->deleteJson("/api/v1/table-sessions/{$session->id}/waiter")
            ->assertOk();
    }

    public function test_manager_with_permission_can_assign(): void
    {
        [$organization, , $restaurant] = $this->createTenant();
        $manager = $this->createStaff($organization, $restaurant, 'manager', 'M-1');
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $manager);
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');

        $this->actingAs($manager, 'web')
            ->putJson("/api/v1/table-sessions/{$session->id}/waiter", ['user_id' => $waiter->id])
            ->assertOk();
    }

    public function test_waiter_without_assign_waiters_permission_is_forbidden(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $requester = $this->createStaff($organization, $restaurant, 'waiter', 'W-REQ');
        $target = $this->createStaff($organization, $restaurant, 'waiter', 'W-TGT');
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);

        $this->actingAs($requester, 'web')
            ->putJson("/api/v1/table-sessions/{$session->id}/waiter", ['user_id' => $target->id])
            ->assertForbidden();

        $this->assertNull($session->fresh()->assigned_waiter_user_id);
    }

    public function test_kitchen_is_forbidden(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $kitchen = $this->createStaff($organization, $restaurant, 'kitchen', 'K-1');
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);

        $this->actingAs($kitchen, 'web')
            ->putJson("/api/v1/table-sessions/{$session->id}/waiter", ['user_id' => $waiter->id])
            ->assertForbidden();
    }

    public function test_cashier_is_forbidden(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $cashier = $this->createStaff($organization, $restaurant, 'cashier', 'C-1');
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);

        $this->actingAs($cashier, 'web')
            ->putJson("/api/v1/table-sessions/{$session->id}/waiter", ['user_id' => $waiter->id])
            ->assertForbidden();
    }

    public function test_manager_outside_restaurant_scope_gets_not_found(): void
    {
        [$organization, $owner, $restaurantA] = $this->createTenant();
        $restaurantB = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $managerB = $this->createStaff($organization, $restaurantB, 'manager', 'M-B');
        $waiterA = $this->createStaff($organization, $restaurantA, 'waiter', 'W-A');
        $table = $this->createTable($restaurantA);
        $session = $this->openSession($table, $owner);

        $this->actingAs($managerB, 'web')
            ->putJson("/api/v1/table-sessions/{$session->id}/waiter", ['user_id' => $waiterA->id])
            ->assertNotFound();

        $this->assertNull($session->fresh()->assigned_waiter_user_id);
    }

    public function test_cross_tenant_owner_gets_not_found(): void
    {
        [, $ownerA, $restaurantA] = $this->createTenant();
        $table = $this->createTable($restaurantA);
        $session = $this->openSession($table, $ownerA);

        [$organizationB, $ownerB, $restaurantB] = $this->createTenant();
        $waiterB = $this->createStaff($organizationB, $restaurantB, 'waiter', 'W-B');

        $this->actingAs($ownerB, 'web')
            ->putJson("/api/v1/table-sessions/{$session->id}/waiter", ['user_id' => $waiterB->id])
            ->assertNotFound();

        $this->assertNull($session->fresh()->assigned_waiter_user_id);
    }

    public function test_guest_is_unauthenticated(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);
        $waiter = User::factory()->create();

        $this->putJson("/api/v1/table-sessions/{$session->id}/waiter", ['user_id' => $waiter->id])
            ->assertUnauthorized();
    }

    public function test_unassign_requires_the_same_permission(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $requester = $this->createStaff($organization, $restaurant, 'waiter', 'W-REQ');
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);

        $this->actingAs($requester, 'web')
            ->deleteJson("/api/v1/table-sessions/{$session->id}/waiter")
            ->assertForbidden();
    }
}
