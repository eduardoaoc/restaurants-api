<?php

namespace Tests\Feature\Operations;

use App\Actions\Tables\AssignWaiterAction;
use App\Actions\Tables\CallResponsibleWaiterAction;
use App\Models\Order;
use App\Models\TableRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithOrders;
use Tests\Concerns\InteractsWithTableRequests;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bloco 5 — Operations Live: alerts, deduplication, and the
 * health/bottleneck integration built on top of them.
 */
class LiveSnapshotAlertsTest extends TestCase
{
    use InteractsWithOrders, InteractsWithTableRequests, InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    public function test_active_table_unassigned_alert(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);

        $response = $this->actingAs($owner, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/operations/live")
            ->assertOk();

        $alert = collect($response->json('data.alerts'))->firstWhere('type', 'active_table_unassigned');
        $this->assertNotNull($alert);
        $this->assertSame('warning', $alert['severity']);
        $this->assertSame($table->id, $alert['table_id']);
        $this->assertSame($session->id, $alert['table_session_id']);
        $this->assertIsInt($alert['age_seconds']);
    }

    public function test_assigned_waiter_suspended_is_critical_and_assignment_is_preserved(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);
        app(AssignWaiterAction::class)->execute($session, $waiter, $owner);
        $waiter->update(['status' => User::STATUS_SUSPENDED]);

        $response = $this->actingAs($owner, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/operations/live")
            ->assertOk();

        $this->assertSame($waiter->id, $session->fresh()->assigned_waiter_user_id);

        $alert = collect($response->json('data.alerts'))->firstWhere('type', 'assigned_waiter_suspended');
        $this->assertNotNull($alert);
        $this->assertSame('critical', $alert['severity']);
        $this->assertSame($waiter->id, $alert['user_id']);

        // A suspended-and-assigned waiter is off-shift by definition too,
        // but must surface as exactly one alert (suspended, the more
        // severe fact), never both.
        $this->assertNull(collect($response->json('data.alerts'))->firstWhere('type', 'assigned_waiter_off_shift'));

        $this->assertSame(80, $response->json('data.operation.health_score'));
        $this->assertSame('healthy', $response->json('data.operation.health_level'));
    }

    public function test_customer_waiter_request_and_bill_request_pending_alerts(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);
        $this->createTableRequest($table, TableRequest::TYPE_CALL_WAITER);
        $this->createTableRequest($table, TableRequest::TYPE_REQUEST_BILL);

        $response = $this->actingAs($owner, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/operations/live")
            ->assertOk();

        $types = collect($response->json('data.alerts'))->pluck('type')->all();
        $this->assertContains('customer_waiter_request_pending', $types);
        $this->assertContains('bill_request_pending', $types);
    }

    public function test_responsible_waiter_call_pending_alert(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);
        app(AssignWaiterAction::class)->execute($session, $waiter, $owner);
        app(CallResponsibleWaiterAction::class)->execute($session, $owner);

        $response = $this->actingAs($owner, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/operations/live")
            ->assertOk();

        $alert = collect($response->json('data.alerts'))->firstWhere('type', 'responsible_waiter_call_pending');
        $this->assertNotNull($alert);
        $this->assertSame($table->id, $alert['table_id']);
        $this->assertSame($waiter->id, $alert['user_id']);
    }

    public function test_order_waiting_approval_and_ready_alerts(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $restaurant->settings()->update(['customer_order_requires_approval' => true]);
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);
        $restaurantProduct = $this->createRestaurantProduct($restaurant, $this->createProduct($organization), 10.0);

        $this->createCustomerOrder($table, [
            ['restaurant_product_id' => $restaurantProduct->id, 'quantity' => 1],
        ]);

        $readyOrder = $this->createWaiterOrder($table, $owner, [
            ['restaurant_product_id' => $restaurantProduct->id, 'quantity' => 1],
        ]);
        $this->advanceOrderTo($readyOrder, Order::STATUS_READY, $owner);

        $response = $this->actingAs($owner, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/operations/live")
            ->assertOk();

        $types = collect($response->json('data.alerts'))->pluck('type')->all();
        $this->assertContains('order_waiting_approval', $types);
        $this->assertContains('order_ready', $types);

        $readyAlert = collect($response->json('data.alerts'))->firstWhere('type', 'order_ready');
        $this->assertSame('info', $readyAlert['severity']);
    }

    public function test_a_single_operational_fact_never_produces_duplicate_alerts(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);

        $response = $this->actingAs($owner, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/operations/live")
            ->assertOk();

        $unassignedAlerts = collect($response->json('data.alerts'))->where('type', 'active_table_unassigned');
        $this->assertCount(1, $unassignedAlerts);
    }

    public function test_bottleneck_reflects_the_most_severe_alert(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');
        $tableA = $this->createTable($restaurant);
        $tableB = $this->createTable($restaurant);

        // Two warnings...
        $this->openSession($tableA, $owner);
        $this->openSession($tableB, $owner);

        // ...and one critical, which must win regardless of count.
        $tableC = $this->createTable($restaurant);
        $sessionC = $this->openSession($tableC, $owner);
        app(AssignWaiterAction::class)->execute($sessionC, $waiter, $owner);
        $waiter->update(['status' => User::STATUS_SUSPENDED]);

        $response = $this->actingAs($owner, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/operations/live")
            ->assertOk();

        $this->assertSame('assigned_waiter_suspended', $response->json('data.operation.bottleneck.type'));
        $this->assertSame('critical', $response->json('data.operation.bottleneck.severity'));
        $this->assertSame(1, $response->json('data.operation.bottleneck.affected_count'));
    }

    public function test_no_alerts_means_null_bottleneck_and_full_health(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $this->createTable($restaurant);

        $response = $this->actingAs($owner, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/operations/live")
            ->assertOk();

        $this->assertNull($response->json('data.operation.bottleneck'));
        $this->assertSame(100, $response->json('data.operation.health_score'));
    }
}
