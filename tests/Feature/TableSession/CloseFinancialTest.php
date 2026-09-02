<?php

namespace Tests\Feature\TableSession;

use App\Actions\Orders\RejectOrderAction;
use App\Actions\Tables\OpenTableAction;
use App\Models\Order;
use App\Models\TableRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithOrders;
use Tests\Concerns\InteractsWithPayments;
use Tests\Concerns\InteractsWithTableRequests;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class CloseFinancialTest extends TestCase
{
    use InteractsWithOrders, InteractsWithPayments, InteractsWithTableRequests, InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    // --- Success ------------------------------------------------------

    public function test_close_succeeds_when_served_and_fully_paid(): void
    {
        [, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);
        $order = $this->createWaiterOrder($table, $owner, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);
        $this->advanceOrderTo($order, Order::STATUS_SERVED, $owner);
        $this->recordPayment($session, $owner, $order->total);

        $response = $this->actingAs($owner, 'web')
            ->postJson("/api/v1/tables/{$table->id}/close")
            ->assertOk();

        $response->assertJsonPath('data.session.status', 'closed');
        $session->refresh();
        $this->assertNotNull($session->closed_at);
        $this->assertSame($owner->id, $session->closed_by_user_id);
    }

    public function test_close_allows_a_cancelled_order_alongside_a_served_one(): void
    {
        [, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);

        // Both orders are created before any payment: once the session is
        // paid, new orders are blocked (see PaidSessionBlocksTest).
        $cancelled = $this->createCustomerOrder($table, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);
        app(RejectOrderAction::class)->execute($cancelled, $owner);

        $served = $this->createWaiterOrder($table, $owner, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);
        $this->advanceOrderTo($served, Order::STATUS_SERVED, $owner);
        $this->recordPayment($session, $owner, $served->total);

        $this->actingAs($owner, 'web')
            ->postJson("/api/v1/tables/{$table->id}/close")
            ->assertOk();
    }

    // --- Unpaid ---------------------------------------------------------

    public function test_close_unpaid_returns_409(): void
    {
        [, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);
        $order = $this->createWaiterOrder($table, $owner, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);
        $this->advanceOrderTo($order, Order::STATUS_SERVED, $owner);

        $response = $this->actingAs($owner, 'web')
            ->postJson("/api/v1/tables/{$table->id}/close")
            ->assertStatus(409);

        $response->assertJson(['error' => ['code' => 'TABLE_SESSION_NOT_PAID']]);
    }

    public function test_close_partially_paid_returns_409(): void
    {
        [, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $rp->update(['price' => 100.0]);
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);
        $order = $this->createWaiterOrder($table, $owner, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);
        $this->advanceOrderTo($order, Order::STATUS_SERVED, $owner);
        $this->recordPayment($session, $owner, '40.00');

        $this->actingAs($owner, 'web')
            ->postJson("/api/v1/tables/{$table->id}/close")
            ->assertStatus(409)
            ->assertJson(['error' => ['code' => 'TABLE_SESSION_NOT_PAID']]);
    }

    // --- Open orders ---------------------------------------------------

    public function test_close_with_confirmed_order_returns_open_orders_conflict(): void
    {
        [, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);
        $this->createWaiterOrder($table, $owner, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);

        $this->actingAs($owner, 'web')
            ->postJson("/api/v1/tables/{$table->id}/close")
            ->assertStatus(409)
            ->assertJson(['error' => ['code' => 'TABLE_SESSION_HAS_OPEN_ORDERS']]);
    }

    public function test_close_with_preparing_order_returns_open_orders_conflict(): void
    {
        [, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);
        $order = $this->createWaiterOrder($table, $owner, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);
        $this->advanceOrderTo($order, Order::STATUS_PREPARING, $owner);
        $this->recordPayment($session, $owner, $order->total);

        $this->actingAs($owner, 'web')
            ->postJson("/api/v1/tables/{$table->id}/close")
            ->assertStatus(409)
            ->assertJson(['error' => ['code' => 'TABLE_SESSION_HAS_OPEN_ORDERS']]);
    }

    public function test_close_with_ready_order_returns_open_orders_conflict(): void
    {
        [, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);
        $order = $this->createWaiterOrder($table, $owner, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);
        $this->advanceOrderTo($order, Order::STATUS_READY, $owner);
        $this->recordPayment($session, $owner, $order->total);

        $this->actingAs($owner, 'web')
            ->postJson("/api/v1/tables/{$table->id}/close")
            ->assertStatus(409)
            ->assertJson(['error' => ['code' => 'TABLE_SESSION_HAS_OPEN_ORDERS']]);
    }

    public function test_close_with_only_waiting_approval_order_returns_open_orders_conflict(): void
    {
        [, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);
        $this->createCustomerOrder($table, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);

        $this->actingAs($owner, 'web')
            ->postJson("/api/v1/tables/{$table->id}/close")
            ->assertStatus(409)
            ->assertJson(['error' => ['code' => 'TABLE_SESSION_HAS_OPEN_ORDERS']]);
    }

    // --- No billable orders ----------------------------------------------

    public function test_close_with_only_cancelled_orders_returns_no_billable_orders_conflict(): void
    {
        [, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);
        $order = $this->createCustomerOrder($table, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);
        app(RejectOrderAction::class)->execute($order, $owner);

        $this->actingAs($owner, 'web')
            ->postJson("/api/v1/tables/{$table->id}/close")
            ->assertStatus(409)
            ->assertJson(['error' => ['code' => 'TABLE_SESSION_HAS_NO_BILLABLE_ORDERS']]);
    }

    public function test_close_with_zero_orders_returns_no_billable_orders_conflict(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);

        $this->actingAs($owner, 'web')
            ->postJson("/api/v1/tables/{$table->id}/close")
            ->assertStatus(409)
            ->assertJson(['error' => ['code' => 'TABLE_SESSION_HAS_NO_BILLABLE_ORDERS']]);
    }

    // --- Already closed --------------------------------------------------

    public function test_closing_an_already_closed_table_returns_controlled_conflict_not_500(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        app(OpenTableAction::class)->execute($table, $owner, 4);

        $this->actingAs($owner, 'web')
            ->postJson("/api/v1/tables/{$table->id}/close")
            ->assertStatus(409);
    }

    // --- TableRequest cleanup ---------------------------------------------

    public function test_open_table_requests_are_cancelled_on_close(): void
    {
        [, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);
        $order = $this->createWaiterOrder($table, $owner, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);
        $this->advanceOrderTo($order, Order::STATUS_SERVED, $owner);

        // Requests must be created before payment: once the session is
        // paid, new public requests are blocked (see PaidSessionBlocksTest).
        $pendingCallWaiter = $this->createTableRequest($table, TableRequest::TYPE_CALL_WAITER);
        $acknowledgedBill = $this->createTableRequest($table, TableRequest::TYPE_REQUEST_BILL);
        $this->advanceTableRequestTo($acknowledgedBill, TableRequest::STATUS_ACKNOWLEDGED, $owner);

        $this->recordPayment($session, $owner, $order->total);

        $this->actingAs($owner, 'web')
            ->postJson("/api/v1/tables/{$table->id}/close")
            ->assertOk();

        $pendingCallWaiter->refresh();
        $acknowledgedBill->refresh();

        $this->assertSame(TableRequest::STATUS_CANCELLED, $pendingCallWaiter->status);
        $this->assertNotNull($pendingCallWaiter->cancelled_at);
        $this->assertSame($owner->id, $pendingCallWaiter->cancelled_by_user_id);

        $this->assertSame(TableRequest::STATUS_CANCELLED, $acknowledgedBill->status);
        $this->assertNotNull($acknowledgedBill->cancelled_at);
        $this->assertSame($owner->id, $acknowledgedBill->cancelled_by_user_id);
    }

    public function test_request_bill_gets_no_special_treatment_it_is_cancelled_like_any_other(): void
    {
        [, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);
        $order = $this->createWaiterOrder($table, $owner, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);
        $this->advanceOrderTo($order, Order::STATUS_SERVED, $owner);
        $bill = $this->createTableRequest($table, TableRequest::TYPE_REQUEST_BILL);
        $this->recordPayment($session, $owner, $order->total);

        $this->actingAs($owner, 'web')->postJson("/api/v1/tables/{$table->id}/close")->assertOk();

        $bill->refresh();
        $this->assertSame(TableRequest::STATUS_CANCELLED, $bill->status);
    }

    public function test_already_finished_requests_are_untouched_by_close(): void
    {
        [, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);
        $order = $this->createWaiterOrder($table, $owner, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);
        $this->advanceOrderTo($order, Order::STATUS_SERVED, $owner);

        $completed = $this->createTableRequest($table, TableRequest::TYPE_CALL_WAITER);
        $this->advanceTableRequestTo($completed, TableRequest::STATUS_COMPLETED, $owner);

        $this->recordPayment($session, $owner, $order->total);

        $this->actingAs($owner, 'web')->postJson("/api/v1/tables/{$table->id}/close")->assertOk();

        $completed->refresh();
        $this->assertSame(TableRequest::STATUS_COMPLETED, $completed->status);
    }

    // --- Table stays active -----------------------------------------

    public function test_table_status_is_unaffected_by_closing_its_session(): void
    {
        [, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);
        $order = $this->createWaiterOrder($table, $owner, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);
        $this->advanceOrderTo($order, Order::STATUS_SERVED, $owner);
        $this->recordPayment($session, $owner, $order->total);

        $this->actingAs($owner, 'web')->postJson("/api/v1/tables/{$table->id}/close")->assertOk();

        $table->refresh();
        $this->assertSame('active', $table->status);
        $this->assertNull($table->activeSession);
    }
}
