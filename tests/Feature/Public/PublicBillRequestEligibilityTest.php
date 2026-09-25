<?php

namespace Tests\Feature\Public;

use App\Actions\Orders\RejectOrderAction;
use App\Actions\Orders\TransitionOrderStatusAction;
use App\Actions\Tables\CloseTableAction;
use App\Models\Order;
use App\Models\RestaurantProduct;
use App\Models\Table;
use App\Models\TableRequest;
use App\Models\TableSession;
use App\Models\User;
use App\Support\Billing\SessionBillCalculator;
use App\Support\Money\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\InteractsWithOrders;
use Tests\Concerns\InteractsWithPayments;
use Tests\Concerns\InteractsWithTableRequests;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * POST /public/tables/{publicToken}/requests/bill is only accepted once the
 * service is done: at least one billable order and no order still open
 * (SessionBillCalculator's hasBillableOrders/hasOpenOrders). call_waiter is
 * not affected.
 */
class PublicBillRequestEligibilityTest extends TestCase
{
    use InteractsWithOrders, InteractsWithPayments, InteractsWithTableRequests, InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
    }

    /**
     * @return array{0: Table, 1: TableSession, 2: User, 3: RestaurantProduct}
     */
    private function activeSession(): array
    {
        [, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);

        return [$table, $session, $owner, $rp];
    }

    private function orderAt(Table $table, User $owner, RestaurantProduct $rp, string $status): Order
    {
        $order = $this->createWaiterOrder($table, $owner, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);

        return $status === Order::STATUS_CONFIRMED ? $order : $this->advanceOrderTo($order, $status, $owner);
    }

    private function cancelledOrder(Table $table, User $owner, RestaurantProduct $rp): Order
    {
        $this->requireOrderApproval($table->restaurant);
        $order = $this->createCustomerOrder($table, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);

        return app(RejectOrderAction::class)->execute($order, $owner);
    }

    private function payInFull(TableSession $session, User $owner): void
    {
        $balance = SessionBillCalculator::summarize($session->refresh())['balanceCents'];
        $this->recordPayment($session, $owner, Money::centsToDecimal($balance));
    }

    private function requestBill(Table $table): TestResponse
    {
        return $this->postJson("/api/v1/public/tables/{$table->public_token}/requests/bill");
    }

    private function assertRejected(TestResponse $response, string $code): void
    {
        $response->assertStatus(409)->assertJson(['error' => ['code' => $code]]);
        $this->assertSame(0, TableRequest::query()->where('type', TableRequest::TYPE_REQUEST_BILL)->count());
    }

    // --- No billable consumption ---------------------------------------------

    public function test_zero_orders_is_rejected_as_no_billable_orders(): void
    {
        [$table] = $this->activeSession();

        $this->assertRejected($this->requestBill($table), 'TABLE_SESSION_HAS_NO_BILLABLE_ORDERS');
        $this->assertDatabaseCount('table_requests', 0);
    }

    public function test_only_waiting_approval_is_rejected_as_open_orders(): void
    {
        // waiting_approval is open (Order::openStatuses()) but not billable;
        // open is checked first, same as CloseTableAction.
        [$table, , , $rp] = $this->activeSession();
        $this->requireOrderApproval($table->restaurant);
        $order = $this->createCustomerOrder($table, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);
        $this->assertSame(Order::STATUS_WAITING_APPROVAL, $order->status);

        $this->assertRejected($this->requestBill($table), 'TABLE_SESSION_HAS_OPEN_ORDERS');
    }

    public function test_only_cancelled_is_rejected_as_no_billable_orders(): void
    {
        [$table, , $owner, $rp] = $this->activeSession();
        $this->assertSame(Order::STATUS_CANCELLED, $this->cancelledOrder($table, $owner, $rp)->status);

        $this->assertRejected($this->requestBill($table), 'TABLE_SESSION_HAS_NO_BILLABLE_ORDERS');
    }

    // --- Service still in progress ---------------------------------------------

    public function test_confirmed_is_rejected_as_open_orders(): void
    {
        [$table, , $owner, $rp] = $this->activeSession();
        $this->orderAt($table, $owner, $rp, Order::STATUS_CONFIRMED);

        $this->assertRejected($this->requestBill($table), 'TABLE_SESSION_HAS_OPEN_ORDERS');
    }

    public function test_accepted_is_rejected_as_open_orders(): void
    {
        [$table, , $owner, $rp] = $this->activeSession();
        $this->orderAt($table, $owner, $rp, Order::STATUS_ACCEPTED);

        $this->assertRejected($this->requestBill($table), 'TABLE_SESSION_HAS_OPEN_ORDERS');
    }

    public function test_preparing_is_rejected_as_open_orders(): void
    {
        [$table, , $owner, $rp] = $this->activeSession();
        $this->orderAt($table, $owner, $rp, Order::STATUS_PREPARING);

        $this->assertRejected($this->requestBill($table), 'TABLE_SESSION_HAS_OPEN_ORDERS');
    }

    public function test_ready_is_rejected_as_open_orders(): void
    {
        [$table, , $owner, $rp] = $this->activeSession();
        $this->orderAt($table, $owner, $rp, Order::STATUS_READY);

        $this->assertRejected($this->requestBill($table), 'TABLE_SESSION_HAS_OPEN_ORDERS');
    }

    // --- Service done -----------------------------------------------------------

    public function test_served_creates_a_pending_request_bill(): void
    {
        [$table, $session, $owner, $rp] = $this->activeSession();
        $this->orderAt($table, $owner, $rp, Order::STATUS_SERVED);

        $this->requestBill($table)
            ->assertStatus(201)
            ->assertJson(['data' => ['type' => TableRequest::TYPE_REQUEST_BILL, 'status' => TableRequest::STATUS_PENDING]]);

        $request = TableRequest::query()->sole();
        $this->assertSame($session->id, $request->table_session_id);
    }

    public function test_served_plus_preparing_is_rejected_as_open_orders(): void
    {
        [$table, , $owner, $rp] = $this->activeSession();
        $this->orderAt($table, $owner, $rp, Order::STATUS_SERVED);
        $this->orderAt($table, $owner, $rp, Order::STATUS_PREPARING);

        $this->assertRejected($this->requestBill($table), 'TABLE_SESSION_HAS_OPEN_ORDERS');
        $this->assertDatabaseCount('table_requests', 0);
    }

    public function test_served_plus_ready_is_rejected_as_open_orders(): void
    {
        [$table, , $owner, $rp] = $this->activeSession();
        $this->orderAt($table, $owner, $rp, Order::STATUS_SERVED);
        $this->orderAt($table, $owner, $rp, Order::STATUS_READY);

        $this->assertRejected($this->requestBill($table), 'TABLE_SESSION_HAS_OPEN_ORDERS');
    }

    public function test_served_plus_waiting_approval_is_rejected_as_open_orders(): void
    {
        [$table, , $owner, $rp] = $this->activeSession();
        $this->orderAt($table, $owner, $rp, Order::STATUS_SERVED);
        $this->requireOrderApproval($table->restaurant);
        $this->createCustomerOrder($table, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);

        $this->assertRejected($this->requestBill($table), 'TABLE_SESSION_HAS_OPEN_ORDERS');
    }

    public function test_served_plus_cancelled_creates_the_request(): void
    {
        [$table, , $owner, $rp] = $this->activeSession();
        $this->orderAt($table, $owner, $rp, Order::STATUS_SERVED);
        $this->cancelledOrder($table, $owner, $rp);

        $this->requestBill($table)->assertStatus(201);
    }

    public function test_multiple_served_orders_create_the_request(): void
    {
        [$table, , $owner, $rp] = $this->activeSession();
        $this->orderAt($table, $owner, $rp, Order::STATUS_SERVED);
        $this->orderAt($table, $owner, $rp, Order::STATUS_SERVED);
        $this->orderAt($table, $owner, $rp, Order::STATUS_SERVED);

        $this->requestBill($table)->assertStatus(201);
        $this->assertDatabaseCount('table_requests', 1);
    }

    public function test_a_rejected_request_can_be_retried_once_the_order_is_served(): void
    {
        [$table, , $owner, $rp] = $this->activeSession();
        $order = $this->orderAt($table, $owner, $rp, Order::STATUS_PREPARING);

        $this->assertRejected($this->requestBill($table), 'TABLE_SESSION_HAS_OPEN_ORDERS');

        $transition = app(TransitionOrderStatusAction::class);
        $transition->serve($transition->markReady($order, $owner), $owner);

        $this->requestBill($table)->assertStatus(201);
    }

    // --- Earlier guards keep precedence -----------------------------------------------

    public function test_paid_active_session_still_returns_already_paid(): void
    {
        [$table, $session, $owner, $rp] = $this->activeSession();
        $this->orderAt($table, $owner, $rp, Order::STATUS_SERVED);
        $this->payInFull($session, $owner);

        $this->assertRejected($this->requestBill($table), 'TABLE_SESSION_ALREADY_PAID');
    }

    public function test_closed_session_with_served_orders_returns_not_active(): void
    {
        [$table, $session, $owner, $rp] = $this->activeSession();
        $this->orderAt($table, $owner, $rp, Order::STATUS_SERVED);
        $this->payInFull($session, $owner);
        app(CloseTableAction::class)->execute($session->refresh(), $owner);

        $this->assertRejected($this->requestBill($table), 'TABLE_SESSION_NOT_ACTIVE');
    }

    public function test_bill_request_disabled_still_wins(): void
    {
        [$table, , $owner, $rp] = $this->activeSession();
        $this->orderAt($table, $owner, $rp, Order::STATUS_SERVED);
        $table->restaurant->settings()->update(['bill_request_enabled' => false]);

        $this->assertRejected($this->requestBill($table), 'BILL_REQUEST_DISABLED');
    }

    public function test_second_open_request_bill_is_rejected_as_already_open(): void
    {
        [$table, , $owner, $rp] = $this->activeSession();
        $this->orderAt($table, $owner, $rp, Order::STATUS_SERVED);

        $this->requestBill($table)->assertStatus(201);
        $this->requestBill($table)
            ->assertStatus(409)
            ->assertJson(['error' => ['code' => 'TABLE_REQUEST_ALREADY_OPEN']]);

        $this->assertDatabaseCount('table_requests', 1);
    }

    // --- call_waiter is unaffected --------------------------------------------------

    public function test_call_waiter_without_orders_is_still_allowed(): void
    {
        [$table] = $this->activeSession();

        $this->postJson("/api/v1/public/tables/{$table->public_token}/requests/call-waiter")
            ->assertStatus(201)
            ->assertJson(['data' => ['type' => TableRequest::TYPE_CALL_WAITER]]);
    }

    public function test_call_waiter_with_open_orders_is_still_allowed(): void
    {
        [$table, , $owner, $rp] = $this->activeSession();
        $this->orderAt($table, $owner, $rp, Order::STATUS_PREPARING);

        $this->postJson("/api/v1/public/tables/{$table->public_token}/requests/call-waiter")
            ->assertStatus(201);
    }

    public function test_call_waiter_on_a_paid_active_session_is_still_allowed(): void
    {
        [$table, $session, $owner, $rp] = $this->activeSession();
        $this->orderAt($table, $owner, $rp, Order::STATUS_SERVED);
        $this->payInFull($session, $owner);

        $this->postJson("/api/v1/public/tables/{$table->public_token}/requests/call-waiter")
            ->assertStatus(201);
    }
}
