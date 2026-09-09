<?php

namespace Tests\Feature\TableSession;

use App\Actions\Tables\AssignWaiterAction;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\TableRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithOrders;
use Tests\Concerns\InteractsWithPayments;
use Tests\Concerns\InteractsWithTableRequests;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bloco 4 — Table Operational Actions: transfer happy path and the
 * preservation guarantees (same session, orders, payments, requests,
 * waiter, guest_count, opened_at all untouched).
 */
class TransferTest extends TestCase
{
    use InteractsWithOrders, InteractsWithPayments, InteractsWithTableRequests, InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    public function test_manager_transfers_an_active_session_to_another_table(): void
    {
        [$organization, , $restaurant] = $this->createTenant();
        $manager = $this->createStaff($organization, $restaurant, 'manager', 'M-1');
        $tableA = $this->createTable($restaurant, 'Mesa A');
        $tableB = $this->createTable($restaurant, 'Mesa B');
        $session = $this->openSession($tableA, $manager, 4);

        $this->actingAs($manager, 'web')
            ->postJson("/api/v1/table-sessions/{$session->id}/transfer", ['target_table_id' => $tableB->id])
            ->assertOk()
            ->assertJsonPath('data.session.id', $session->id)
            ->assertJsonPath('data.session.table_id', $tableB->id)
            ->assertJsonPath('data.session.guest_count', 4);

        $this->assertDatabaseHas('table_sessions', [
            'id' => $session->id,
            'table_id' => $tableB->id,
            'status' => 'occupied',
        ]);
        $this->assertNull($tableA->activeSession()->first());
        $this->assertNotNull($tableB->activeSession()->first());
    }

    public function test_transfer_preserves_started_at_and_guest_count(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $tableA = $this->createTable($restaurant);
        $tableB = $this->createTable($restaurant);
        $session = $this->openSession($tableA, $owner, 6);
        $originalOpenedAt = $session->opened_at;

        $response = $this->actingAs($owner, 'web')
            ->postJson("/api/v1/table-sessions/{$session->id}/transfer", ['target_table_id' => $tableB->id])
            ->assertOk();

        $this->assertSame(6, $response->json('data.session.guest_count'));
        $this->assertSame($originalOpenedAt->toIso8601String(), $session->fresh()->opened_at->toIso8601String());
    }

    public function test_transfer_preserves_assigned_waiter(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1', name: 'Mateo');
        $tableA = $this->createTable($restaurant);
        $tableB = $this->createTable($restaurant);
        $session = $this->openSession($tableA, $owner);
        app(AssignWaiterAction::class)->execute($session, $waiter, $owner);

        $this->actingAs($owner, 'web')
            ->postJson("/api/v1/table-sessions/{$session->id}/transfer", ['target_table_id' => $tableB->id])
            ->assertOk()
            ->assertJsonPath('data.session.assigned_waiter.id', $waiter->id)
            ->assertJsonPath('data.session.assigned_waiter.name', 'Mateo');

        $this->assertSame($waiter->id, $session->fresh()->assigned_waiter_user_id);
    }

    public function test_existing_orders_remain_attached_to_the_same_session_and_follow_the_table(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $tableA = $this->createTable($restaurant);
        $tableB = $this->createTable($restaurant);
        $session = $this->openSession($tableA, $owner);

        $restaurantProduct = $this->createRestaurantProduct($restaurant, $this->createProduct($organization), 10.0);
        $order = $this->createWaiterOrder($tableA, $owner, [
            ['restaurant_product_id' => $restaurantProduct->id, 'quantity' => 2],
        ]);
        $originalTotal = $order->total;
        $originalStatus = $order->status;

        $this->actingAs($owner, 'web')
            ->postJson("/api/v1/table-sessions/{$session->id}/transfer", ['target_table_id' => $tableB->id])
            ->assertOk();

        $order->refresh();
        $this->assertSame($session->id, $order->table_session_id);
        $this->assertSame($tableB->id, $order->table_id);
        $this->assertSame($originalStatus, $order->status);
        $this->assertSame((string) $originalTotal, (string) $order->total);
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_existing_requests_remain_attached_and_follow_the_table(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $tableA = $this->createTable($restaurant);
        $tableB = $this->createTable($restaurant);
        $session = $this->openSession($tableA, $owner);
        $tableRequest = $this->createTableRequest($tableA, TableRequest::TYPE_CALL_WAITER);

        $this->actingAs($owner, 'web')
            ->postJson("/api/v1/table-sessions/{$session->id}/transfer", ['target_table_id' => $tableB->id])
            ->assertOk();

        $tableRequest->refresh();
        $this->assertSame($session->id, $tableRequest->table_session_id);
        $this->assertSame($tableB->id, $tableRequest->table_id);
        $this->assertSame('pending', $tableRequest->status);
        $this->assertDatabaseCount('table_requests', 1);
    }

    public function test_partial_payment_remains_valid_after_transfer(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $tableA = $this->createTable($restaurant);
        $tableB = $this->createTable($restaurant);
        $session = $this->openSession($tableA, $owner);

        $restaurantProduct = $this->createRestaurantProduct($restaurant, $this->createProduct($organization), 100.0);
        $order = $this->createWaiterOrder($tableA, $owner, [
            ['restaurant_product_id' => $restaurantProduct->id, 'quantity' => 1],
        ]);
        $this->advanceOrderTo($order, Order::STATUS_SERVED, $owner);
        $this->recordPayment($session, $owner, '40.00');

        $this->actingAs($owner, 'web')
            ->postJson("/api/v1/table-sessions/{$session->id}/transfer", ['target_table_id' => $tableB->id])
            ->assertOk();

        $this->actingAs($owner, 'web')
            ->getJson("/api/v1/table-sessions/{$session->id}/bill")
            ->assertOk()
            ->assertJsonPath('data.orders_total', '100.00')
            ->assertJsonPath('data.paid_total', '40.00')
            ->assertJsonPath('data.balance', '60.00');

        $this->assertDatabaseCount('payment_records', 1);
        $this->assertDatabaseHas('payment_records', ['table_session_id' => $session->id, 'table_id' => $tableB->id, 'amount' => '40.00']);
    }

    public function test_audit_event_records_from_and_to_table_ids(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $tableA = $this->createTable($restaurant);
        $tableB = $this->createTable($restaurant);
        $session = $this->openSession($tableA, $owner);

        $this->actingAs($owner, 'web')
            ->postJson("/api/v1/table-sessions/{$session->id}/transfer", ['target_table_id' => $tableB->id])
            ->assertOk();

        $log = AuditLog::query()->where('event', AuditLog::EVENT_TABLE_SESSION_TRANSFERRED)->first();
        $this->assertNotNull($log);
        $this->assertSame($restaurant->id, $log->restaurant_id);
        $this->assertSame($organization->id, $log->organization_id);
        $this->assertSame($owner->id, $log->actor_user_id);
        $this->assertSame($session->id, $log->resource_id);
        $this->assertEquals([
            'table_session_id' => $session->id,
            'from_table_id' => $tableA->id,
            'to_table_id' => $tableB->id,
        ], $log->metadata);
    }
}
