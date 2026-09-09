<?php

namespace Tests\Feature\TableSession;

use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithOrders;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bloco 4 — who may transfer a table session: owner/manager
 * (transfer_tables), never waiter/kitchen/cashier by default. Order:
 * cross tenant / outside RestaurantScope -> 404, in scope without
 * permission -> 403, unauthenticated -> 401.
 */
class TransferAuthorizationTest extends TestCase
{
    use InteractsWithOrders, InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    public function test_owner_can_transfer(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $tableA = $this->createTable($restaurant);
        $tableB = $this->createTable($restaurant);
        $session = $this->openSession($tableA, $owner);

        $this->actingAs($owner, 'web')
            ->postJson("/api/v1/table-sessions/{$session->id}/transfer", ['target_table_id' => $tableB->id])
            ->assertOk();
    }

    public function test_manager_with_permission_can_transfer(): void
    {
        [$organization, , $restaurant] = $this->createTenant();
        $manager = $this->createStaff($organization, $restaurant, 'manager', 'M-1');
        $tableA = $this->createTable($restaurant);
        $tableB = $this->createTable($restaurant);
        $session = $this->openSession($tableA, $manager);

        $this->actingAs($manager, 'web')
            ->postJson("/api/v1/table-sessions/{$session->id}/transfer", ['target_table_id' => $tableB->id])
            ->assertOk();
    }

    public function test_waiter_cannot_transfer(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');
        $tableA = $this->createTable($restaurant);
        $tableB = $this->createTable($restaurant);
        $session = $this->openSession($tableA, $owner);

        $this->actingAs($waiter, 'web')
            ->postJson("/api/v1/table-sessions/{$session->id}/transfer", ['target_table_id' => $tableB->id])
            ->assertForbidden();

        $this->assertSame($tableA->id, $session->fresh()->table_id);
    }

    public function test_kitchen_cannot_transfer(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $kitchen = $this->createStaff($organization, $restaurant, 'kitchen', 'K-1');
        $tableA = $this->createTable($restaurant);
        $tableB = $this->createTable($restaurant);
        $session = $this->openSession($tableA, $owner);

        $this->actingAs($kitchen, 'web')
            ->postJson("/api/v1/table-sessions/{$session->id}/transfer", ['target_table_id' => $tableB->id])
            ->assertForbidden();
    }

    public function test_cashier_cannot_transfer(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $cashier = $this->createStaff($organization, $restaurant, 'cashier', 'C-1');
        $tableA = $this->createTable($restaurant);
        $tableB = $this->createTable($restaurant);
        $session = $this->openSession($tableA, $owner);

        $this->actingAs($cashier, 'web')
            ->postJson("/api/v1/table-sessions/{$session->id}/transfer", ['target_table_id' => $tableB->id])
            ->assertForbidden();
    }

    public function test_manager_outside_restaurant_scope_gets_not_found(): void
    {
        [$organization, $owner, $restaurantA] = $this->createTenant();
        $restaurantB = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $managerB = $this->createStaff($organization, $restaurantB, 'manager', 'M-B');
        $tableA1 = $this->createTable($restaurantA);
        $tableA2 = $this->createTable($restaurantA);
        $session = $this->openSession($tableA1, $owner);

        $this->actingAs($managerB, 'web')
            ->postJson("/api/v1/table-sessions/{$session->id}/transfer", ['target_table_id' => $tableA2->id])
            ->assertNotFound();
    }

    public function test_cross_tenant_owner_gets_not_found(): void
    {
        [, $ownerA, $restaurantA] = $this->createTenant();
        [, , $restaurantB] = $this->createTenant();
        $tableA1 = $this->createTable($restaurantA);
        $tableA2 = $this->createTable($restaurantA);
        $tableB = $this->createTable($restaurantB);
        $session = $this->openSession($tableA1, $ownerA);

        $this->actingAs($ownerA, 'web')
            ->postJson("/api/v1/table-sessions/{$session->id}/transfer", ['target_table_id' => $tableB->id])
            ->assertNotFound();

        // Confirm ownerA does have the scoped ability itself — a valid
        // same-restaurant target still works, isolating the 404 above to
        // the cross-tenant target id specifically.
        $this->actingAs($ownerA, 'web')
            ->postJson("/api/v1/table-sessions/{$session->id}/transfer", ['target_table_id' => $tableA2->id])
            ->assertOk();
    }

    public function test_guest_is_unauthenticated(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $tableA = $this->createTable($restaurant);
        $tableB = $this->createTable($restaurant);
        $session = $this->openSession($tableA, $owner);

        $this->postJson("/api/v1/table-sessions/{$session->id}/transfer", ['target_table_id' => $tableB->id])
            ->assertUnauthorized();
    }
}
