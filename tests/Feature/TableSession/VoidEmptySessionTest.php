<?php

namespace Tests\Feature\TableSession;

use App\Actions\Orders\RejectOrderAction;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\RestaurantActivityEvent;
use App\Models\TableRequest;
use App\Models\TableSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithDayClose;
use Tests\Concerns\InteractsWithOrders;
use Tests\Concerns\InteractsWithPayments;
use Tests\Concerns\InteractsWithTableRequests;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * CARTA 9.1A — POST /table-sessions/{id}/void (VoidEmptyTableSessionAction).
 */
class VoidEmptySessionTest extends TestCase
{
    use InteractsWithDayClose, InteractsWithOrders, InteractsWithPayments, InteractsWithTableRequests, InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    public function test_empty_active_session_can_be_voided_and_frees_the_table(): void
    {
        [$organization, , $restaurant] = $this->createTenant();
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $waiter, 3);

        $this->as($waiter)->postJson("/api/v1/table-sessions/{$session->id}/void", ['reason' => 'Mesa abierta por error'])
            ->assertOk()
            ->assertJsonPath('data.session.status', 'closed')
            ->assertJsonPath('data.session.void_reason', 'Mesa abierta por error')
            ->assertJsonPath('data.session.voided_by_user_id', $waiter->id)
            ->assertJsonPath('data.session.closed_by_user_id', null);

        $session->refresh();
        $this->assertFalse($session->isActive());
        $this->assertTrue($session->isVoided());
        $this->assertNotNull($session->closed_at);
        $this->assertNull($table->refresh()->activeSession);

        $this->assertDatabaseHas('audit_logs', ['event' => AuditLog::EVENT_TABLE_SESSION_VOIDED, 'resource_id' => $session->id, 'actor_user_id' => $waiter->id]);
        $this->assertSame(1, RestaurantActivityEvent::query()->where('type', 'table_session.voided')->where('table_session_id', $session->id)->count());

        // The table can be opened again.
        $this->openSession($table, $waiter);
    }

    public function test_session_with_only_rejected_orders_can_be_voided(): void
    {
        [, $owner, $restaurant, $restaurantProduct] = $this->createTenantWithRestaurantProduct();
        $this->requireOrderApproval($restaurant);
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);
        $order = $this->createCustomerOrder($table, [['restaurant_product_id' => $restaurantProduct->id, 'quantity' => 1]]);
        app(RejectOrderAction::class)->execute($order, $owner);

        $this->as($owner)->postJson("/api/v1/table-sessions/{$session->id}/void")->assertOk();
        $this->assertSame(Order::STATUS_CANCELLED, $order->refresh()->status);
    }

    public function test_session_with_a_billable_order_cannot_be_voided(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $session = $this->servedPaidSession($restaurant, $owner, close: false);
        // paid -> has_payments is checked first; build a billable-but-unpaid one too
        $table = $this->createTable($restaurant);
        $unpaid = $this->openSession($table, $owner);
        $this->createServedOrder($table, $owner);

        $this->as($owner)->postJson("/api/v1/table-sessions/{$unpaid->id}/void")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'TABLE_SESSION_NOT_EMPTY')
            ->assertJsonPath('error.reason', 'has_billable_orders');

        $this->assertTrue($unpaid->refresh()->isActive());
        $this->assertTrue($session->refresh()->isActive());
    }

    public function test_session_with_an_open_order_cannot_be_voided(): void
    {
        [, $owner, $restaurant, $restaurantProduct] = $this->createTenantWithRestaurantProduct();
        $this->requireOrderApproval($restaurant);
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);
        $this->createCustomerOrder($table, [['restaurant_product_id' => $restaurantProduct->id, 'quantity' => 1]]);

        $this->as($owner)->postJson("/api/v1/table-sessions/{$session->id}/void")
            ->assertStatus(409)
            ->assertJsonPath('error.reason', 'has_open_orders');
    }

    public function test_session_with_a_payment_cannot_be_voided(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $session = $this->servedPaidSession($restaurant, $owner, close: false);

        $this->as($owner)->postJson("/api/v1/table-sessions/{$session->id}/void")
            ->assertStatus(409)
            ->assertJsonPath('error.reason', 'has_payments');
    }

    public function test_already_closed_session_cannot_be_voided(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $session = $this->servedPaidSession($restaurant, $owner);

        $this->as($owner)->postJson("/api/v1/table-sessions/{$session->id}/void")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'TABLE_SESSION_CLOSED');
    }

    public function test_open_table_requests_are_cancelled_not_deleted(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);
        $request = $this->createTableRequest($table, TableRequest::TYPE_CALL_WAITER);

        $this->as($owner)->postJson("/api/v1/table-sessions/{$session->id}/void")->assertOk();

        $request->refresh();
        $this->assertSame(TableRequest::STATUS_CANCELLED, $request->status);
        $this->assertSame($owner->id, $request->cancelled_by_user_id);
        $this->assertDatabaseHas('audit_logs', ['event' => AuditLog::EVENT_TABLE_REQUEST_CANCELLED, 'resource_id' => $request->id]);
    }

    public function test_wrong_tenant_and_sibling_restaurant_get_404(): void
    {
        [, , $restaurantA] = $this->createTenant();
        [, $ownerB] = $this->createTenant();
        $session = $this->openSession($this->createTable($restaurantA), $ownerB);

        $this->as($ownerB)->postJson("/api/v1/table-sessions/{$session->id}/void")->assertNotFound();
        $this->assertTrue($session->refresh()->isActive());
    }

    public function test_kitchen_cannot_void(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $kitchen = $this->createStaff($organization, $restaurant, 'kitchen', 'K-1');
        $session = $this->openSession($this->createTable($restaurant), $owner);

        $this->as($kitchen)->postJson("/api/v1/table-sessions/{$session->id}/void")->assertForbidden();
    }

    public function test_voided_session_is_not_counted_as_served_anywhere(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $this->at('2026-10-02 18:00:00');
        $this->servedPaidSession($restaurant, $owner, guests: 2);
        $empty = $this->openSession($this->createTable($restaurant), $owner, 6);
        $this->as($owner)->postJson("/api/v1/table-sessions/{$empty->id}/void")->assertOk();
        $this->at('2026-10-02 20:00:00');

        $operations = $this->dayClosePreview($restaurant, $owner)['operations'];
        $this->assertSame(['opened' => 1, 'closed' => 1, 'voided' => 1], $operations['sessions']);
        $this->assertSame(2, $operations['guests']);

        // Existing analytics/dashboard metrics ignore it too.
        $this->as($owner)->getJson("/api/v1/restaurants/{$restaurant->id}/dashboard?from=2026-10-02&to=2026-10-02")
            ->assertOk()
            ->assertJsonPath('data.dashboard.tables.sessions_opened', 1)
            ->assertJsonPath('data.dashboard.tables.sessions_closed', 1);

        $this->as($owner)->getJson("/api/v1/restaurants/{$restaurant->id}/analytics?from=2026-10-02&to=2026-10-02")
            ->assertOk()
            ->assertJsonPath('data.summary.guests_served', 2)
            ->assertJsonPath('data.summary.closed_sessions', 1);

        $this->assertSame(1, TableSession::query()->notVoided()->where('restaurant_id', $restaurant->id)->count());
    }
}
