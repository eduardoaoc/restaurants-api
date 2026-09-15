<?php

namespace Tests\Feature\Public;

use App\Actions\Tables\CloseTableAction;
use App\Models\Order;
use App\Models\TableRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithOrders;
use Tests\Concerns\InteractsWithPayments;
use Tests\Concerns\InteractsWithTableRequests;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Once the customer has requested the bill (request_bill TableRequest,
 * status pending/acknowledged), the public QR surface must stop accepting
 * new orders for that session — see OrderCreationService::$blockIfBillRequested
 * and TableSessionBillRequestedException. Staff ordering, payments and
 * closing are deliberately unaffected: request_bill only ever gates the
 * public ordering endpoint.
 */
class PublicOrderBillRequestedTest extends TestCase
{
    use InteractsWithOrders, InteractsWithPayments, InteractsWithTableRequests, InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
    }

    // --- A: order -> request bill -> blocked -----------------------------

    public function test_public_order_succeeds_then_is_blocked_after_bill_is_requested(): void
    {
        [, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);

        $this->postJson("/api/v1/public/tables/{$table->public_token}/orders", [
            'items' => [['restaurant_product_id' => $rp->id, 'quantity' => 1]],
        ])->assertStatus(201);

        $this->assertDatabaseCount('orders', 1);

        $this->postJson("/api/v1/public/tables/{$table->public_token}/requests/bill")
            ->assertStatus(201);

        $response = $this->postJson("/api/v1/public/tables/{$table->public_token}/orders", [
            'items' => [['restaurant_product_id' => $rp->id, 'quantity' => 1]],
        ])->assertStatus(409);

        $response->assertJson(['error' => ['code' => 'TABLE_SESSION_BILL_REQUESTED']]);
        $this->assertDatabaseCount('orders', 1);
    }

    // --- B: acknowledged request_bill still blocks ------------------------

    public function test_public_order_is_blocked_while_bill_request_is_acknowledged(): void
    {
        [, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);

        $tableRequest = $this->createTableRequest($table, TableRequest::TYPE_REQUEST_BILL);
        $this->advanceTableRequestTo($tableRequest, TableRequest::STATUS_ACKNOWLEDGED, $owner);

        $response = $this->postJson("/api/v1/public/tables/{$table->public_token}/orders", [
            'items' => [['restaurant_product_id' => $rp->id, 'quantity' => 1]],
        ])->assertStatus(409);

        $response->assertJson(['error' => ['code' => 'TABLE_SESSION_BILL_REQUESTED']]);
        $this->assertDatabaseCount('orders', 0);
        $this->assertTrue($session->refresh()->hasOpenBillRequest());
    }

    // --- C: staff ordering is unaffected -----------------------------------

    public function test_staff_order_still_succeeds_while_bill_request_is_open(): void
    {
        [, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);

        $this->createTableRequest($table, TableRequest::TYPE_REQUEST_BILL);

        $response = $this->actingAs($owner, 'web')
            ->postJson("/api/v1/tables/{$table->id}/orders", [
                'items' => [['restaurant_product_id' => $rp->id, 'quantity' => 1]],
            ]);

        $response->assertStatus(201);
        $this->assertDatabaseCount('orders', 1);
        $this->assertSame(Order::ORIGIN_WAITER, Order::query()->firstOrFail()->origin);
    }

    // --- D: no bill request -> public ordering unaffected -------------------

    public function test_public_order_still_succeeds_without_any_bill_request(): void
    {
        [, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);

        $this->postJson("/api/v1/public/tables/{$table->public_token}/orders", [
            'items' => [['restaurant_product_id' => $rp->id, 'quantity' => 1]],
        ])->assertStatus(201);

        $this->assertDatabaseCount('orders', 1);
    }

    // --- E: payment and close are unaffected by an open bill request -------

    public function test_payment_is_unaffected_by_an_open_bill_request(): void
    {
        [, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);
        $order = $this->createWaiterOrder($table, $owner, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);
        $order = $this->advanceOrderTo($order, Order::STATUS_SERVED, $owner);

        $this->createTableRequest($table, TableRequest::TYPE_REQUEST_BILL);

        ['payment' => $payment] = $this->recordPayment($session, $owner, $order->total);

        $this->assertNotNull($payment->id);
        $this->assertTrue($session->refresh()->isPaid());
    }

    public function test_close_is_unaffected_by_an_open_bill_request(): void
    {
        [, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);
        $order = $this->createWaiterOrder($table, $owner, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);
        $order = $this->advanceOrderTo($order, Order::STATUS_SERVED, $owner);

        $tableRequest = $this->createTableRequest($table, TableRequest::TYPE_REQUEST_BILL);
        $this->recordPayment($session, $owner, $order->total);

        $closed = app(CloseTableAction::class)->execute($session->refresh(), $owner);

        $this->assertSame('closed', $closed->status);
        $this->assertNull($table->refresh()->activeSession);
        $this->assertSame(TableRequest::STATUS_CANCELLED, $tableRequest->refresh()->status);
    }
}
