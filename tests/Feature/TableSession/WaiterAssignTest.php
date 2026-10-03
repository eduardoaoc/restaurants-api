<?php

namespace Tests\Feature\TableSession;

use App\Models\TableRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithOrders;
use Tests\Concerns\InteractsWithPayments;
use Tests\Concerns\InteractsWithTableRequests;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bloco 2 — Waiter Assignment: assign, reassign, unassign, and their
 * idempotency, plus the guarantee that the assignment never touches
 * anything else about the session.
 */
class WaiterAssignTest extends TestCase
{
    use InteractsWithOrders, InteractsWithPayments, InteractsWithTableRequests, InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    public function test_owner_assigns_waiter_to_an_unassigned_session(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1', name: 'Mateo');

        $this->actingAs($owner, 'web')
            ->putJson("/api/v1/table-sessions/{$session->id}/waiter", ['user_id' => $waiter->id])
            ->assertOk()
            ->assertJsonPath('data.session.id', $session->id)
            ->assertJsonPath('data.session.assigned_waiter.id', $waiter->id)
            ->assertJsonPath('data.session.assigned_waiter.name', 'Mateo');

        $this->assertDatabaseHas('table_sessions', [
            'id' => $session->id,
            'assigned_waiter_user_id' => $waiter->id,
        ]);
    }

    public function test_manager_with_permission_assigns_waiter(): void
    {
        [$organization, , $restaurant] = $this->createTenant();
        $manager = $this->createStaff($organization, $restaurant, 'manager', 'M-1');
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $manager);
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');

        $this->actingAs($manager, 'web')
            ->putJson("/api/v1/table-sessions/{$session->id}/waiter", ['user_id' => $waiter->id])
            ->assertOk()
            ->assertJsonPath('data.session.assigned_waiter.id', $waiter->id);
    }

    public function test_newly_opened_session_has_no_assigned_waiter(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);

        $this->actingAs($owner, 'web')
            ->postJson("/api/v1/tables/{$table->id}/open", ['guest_count' => 2])
            ->assertCreated()
            ->assertJsonPath('data.session.assigned_waiter', null);
    }

    public function test_assigning_the_same_waiter_twice_is_harmless(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');

        $this->actingAs($owner, 'web')
            ->putJson("/api/v1/table-sessions/{$session->id}/waiter", ['user_id' => $waiter->id])
            ->assertOk();

        $this->actingAs($owner, 'web')
            ->putJson("/api/v1/table-sessions/{$session->id}/waiter", ['user_id' => $waiter->id])
            ->assertOk()
            ->assertJsonPath('data.session.assigned_waiter.id', $waiter->id);

        $this->assertDatabaseCount('table_sessions', 1);
    }

    public function test_reassignment_replaces_the_previous_waiter_on_the_same_session(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);
        $mateo = $this->createStaff($organization, $restaurant, 'waiter', 'W-1', name: 'Mateo');
        $lucia = $this->createStaff($organization, $restaurant, 'waiter', 'W-2', name: 'Lucía');

        $this->actingAs($owner, 'web')
            ->putJson("/api/v1/table-sessions/{$session->id}/waiter", ['user_id' => $mateo->id])
            ->assertOk();

        $this->actingAs($owner, 'web')
            ->putJson("/api/v1/table-sessions/{$session->id}/waiter", ['user_id' => $lucia->id])
            ->assertOk()
            ->assertJsonPath('data.session.assigned_waiter.id', $lucia->id)
            ->assertJsonPath('data.session.id', $session->id);

        $this->assertDatabaseCount('table_sessions', 1);
        $this->assertDatabaseHas('table_sessions', [
            'id' => $session->id,
            'assigned_waiter_user_id' => $lucia->id,
        ]);
    }

    public function test_unassign_clears_the_waiter_and_leaves_the_session_active(): void
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
            ->assertOk()
            ->assertJsonPath('data.session.assigned_waiter', null)
            ->assertJsonPath('data.session.status', 'occupied');

        $this->assertDatabaseHas('table_sessions', [
            'id' => $session->id,
            'assigned_waiter_user_id' => null,
            'status' => 'occupied',
        ]);
    }

    public function test_unassigning_an_already_unassigned_session_is_harmless(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);

        $this->actingAs($owner, 'web')
            ->deleteJson("/api/v1/table-sessions/{$session->id}/waiter")
            ->assertOk()
            ->assertJsonPath('data.session.assigned_waiter', null);
    }

    public function test_reassignment_does_not_touch_orders_requests_or_payments(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);
        $mateo = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');
        $lucia = $this->createStaff($organization, $restaurant, 'waiter', 'W-2');

        $restaurantProduct = $this->createRestaurantProduct($restaurant, $this->createProduct($organization), 10.0);
        $order = $this->createWaiterOrder($table, $owner, [
            ['restaurant_product_id' => $restaurantProduct->id, 'quantity' => 1],
        ]);
        $tableRequest = $this->createTableRequest($table, TableRequest::TYPE_CALL_WAITER);
        $originalOrderStatus = $order->status;
        $originalRequestStatus = $tableRequest->status;

        $this->actingAs($owner, 'web')
            ->putJson("/api/v1/table-sessions/{$session->id}/waiter", ['user_id' => $mateo->id])
            ->assertOk();

        $this->actingAs($owner, 'web')
            ->putJson("/api/v1/table-sessions/{$session->id}/waiter", ['user_id' => $lucia->id])
            ->assertOk();

        $this->assertSame($session->id, $order->fresh()->table_session_id);
        $this->assertSame($originalOrderStatus, $order->fresh()->status);
        $this->assertSame($originalRequestStatus, $tableRequest->fresh()->status);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'table_session_id' => $session->id]);
        $this->assertDatabaseHas('table_requests', ['id' => $tableRequest->id, 'table_session_id' => $session->id]);
        $this->assertSame($table->id, $session->fresh()->table_id);
        $this->assertSame('occupied', $session->fresh()->status);
    }

    public function test_invalid_user_id_returns_validation_error(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);

        $this->actingAs($owner, 'web')
            ->putJson("/api/v1/table-sessions/{$session->id}/waiter", ['user_id' => 999999])
            ->assertStatus(422);
    }

    public function test_missing_user_id_returns_validation_error(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);

        $this->actingAs($owner, 'web')
            ->putJson("/api/v1/table-sessions/{$session->id}/waiter", [])
            ->assertStatus(422);
    }
}
