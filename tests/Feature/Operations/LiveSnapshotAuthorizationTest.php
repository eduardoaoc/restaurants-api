<?php

namespace Tests\Feature\Operations;

use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bloco 5 — Operations Live authorization: view_operations (owner/manager,
 * and waiter since the Passo 3.2 fix — see RolePermissionSeeder — but
 * never kitchen/cashier), RestaurantScope, cross tenant, guest.
 */
class LiveSnapshotAuthorizationTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    public function test_owner_is_allowed(): void
    {
        [, $owner, $restaurant] = $this->createTenant();

        $this->actingAs($owner, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/operations/live")
            ->assertOk();
    }

    public function test_manager_with_permission_is_allowed(): void
    {
        [$organization, , $restaurant] = $this->createTenant();
        $manager = $this->createStaff($organization, $restaurant, 'manager', 'M-1');

        $this->actingAs($manager, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/operations/live")
            ->assertOk();
    }

    /**
     * Passo 3.2 fix: a waiter needs the live operations snapshot to work
     * the floor (tables/sessions/orders in real time) — see
     * RolePermissionSeeder's waiter view_operations grant and the fix
     * report. Read-only; a waiter still cannot manage the Carta (see
     * WaiterOperationalAccessTest).
     */
    public function test_waiter_is_allowed(): void
    {
        [$organization, , $restaurant] = $this->createTenant();
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');

        $this->actingAs($waiter, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/operations/live")
            ->assertOk();
    }

    public function test_kitchen_is_forbidden(): void
    {
        [$organization, , $restaurant] = $this->createTenant();
        $kitchen = $this->createStaff($organization, $restaurant, 'kitchen', 'K-1');

        $this->actingAs($kitchen, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/operations/live")
            ->assertForbidden();
    }

    public function test_cashier_is_forbidden(): void
    {
        [$organization, , $restaurant] = $this->createTenant();
        $cashier = $this->createStaff($organization, $restaurant, 'cashier', 'C-1');

        $this->actingAs($cashier, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/operations/live")
            ->assertForbidden();
    }

    public function test_manager_outside_restaurant_scope_gets_not_found(): void
    {
        [$organization, , $restaurantA] = $this->createTenant();
        $restaurantB = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $managerA = $this->createStaff($organization, $restaurantA, 'manager', 'M-A');

        $this->actingAs($managerA, 'web')
            ->getJson("/api/v1/restaurants/{$restaurantB->id}/operations/live")
            ->assertNotFound();
    }

    public function test_cross_tenant_owner_gets_not_found(): void
    {
        [, $ownerA] = $this->createTenant();
        [, , $restaurantB] = $this->createTenant();

        $this->actingAs($ownerA, 'web')
            ->getJson("/api/v1/restaurants/{$restaurantB->id}/operations/live")
            ->assertNotFound();
    }

    public function test_guest_is_unauthenticated(): void
    {
        [, , $restaurant] = $this->createTenant();

        $this->getJson("/api/v1/restaurants/{$restaurant->id}/operations/live")
            ->assertUnauthorized();
    }
}
