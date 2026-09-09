<?php

namespace Tests\Feature\Analytics;

use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bloco 6 — Analytics authorization: reuses view_reports (owner/manager
 * by default, never waiter/kitchen/cashier — same permission the existing
 * /dashboard endpoint requires), RestaurantScope, cross-tenant, guest.
 */
class AnalyticsAuthorizationTest extends TestCase
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
            ->getJson("/api/v1/restaurants/{$restaurant->id}/analytics")
            ->assertOk();
    }

    public function test_manager_with_view_reports_is_allowed(): void
    {
        [$organization, , $restaurant] = $this->createTenant();
        $manager = $this->createStaff($organization, $restaurant, 'manager', 'M-1');

        $this->actingAs($manager, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/analytics")
            ->assertOk();
    }

    public function test_waiter_without_view_reports_is_forbidden(): void
    {
        [$organization, , $restaurant] = $this->createTenant();
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');

        $this->actingAs($waiter, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/analytics")
            ->assertForbidden();
    }

    public function test_kitchen_is_forbidden(): void
    {
        [$organization, , $restaurant] = $this->createTenant();
        $kitchen = $this->createStaff($organization, $restaurant, 'kitchen', 'K-1');

        $this->actingAs($kitchen, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/analytics")
            ->assertForbidden();
    }

    public function test_cashier_is_forbidden(): void
    {
        [$organization, , $restaurant] = $this->createTenant();
        $cashier = $this->createStaff($organization, $restaurant, 'cashier', 'C-1');

        $this->actingAs($cashier, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/analytics")
            ->assertForbidden();
    }

    public function test_manager_outside_restaurant_scope_gets_not_found(): void
    {
        [$organization, , $restaurantA] = $this->createTenant();
        $restaurantB = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $managerA = $this->createStaff($organization, $restaurantA, 'manager', 'M-A');

        $this->actingAs($managerA, 'web')
            ->getJson("/api/v1/restaurants/{$restaurantB->id}/analytics")
            ->assertNotFound();
    }

    public function test_cross_tenant_owner_gets_not_found(): void
    {
        [, $ownerA] = $this->createTenant();
        [, , $restaurantB] = $this->createTenant();

        $this->actingAs($ownerA, 'web')
            ->getJson("/api/v1/restaurants/{$restaurantB->id}/analytics")
            ->assertNotFound();
    }

    public function test_guest_is_unauthenticated(): void
    {
        [, , $restaurant] = $this->createTenant();

        $this->getJson("/api/v1/restaurants/{$restaurant->id}/analytics")
            ->assertUnauthorized();
    }
}
