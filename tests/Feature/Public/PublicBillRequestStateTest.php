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
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithOrders;
use Tests\Concerns\InteractsWithPayments;
use Tests\Concerns\InteractsWithTableRequests;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * session.bill_request (CARTA 5.1D): the backend-derived, presentation-only
 * {eligible, reason} projection on GET /public/tables/{token} and
 * GET /public/tables/{token}/menu. The POST stays authoritative — see
 * PublicBillRequestEligibilityTest.
 */
class PublicBillRequestStateTest extends TestCase
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
        // An active menu, so GET /menu answers 200 and both endpoints can be compared.
        $this->createMenu($restaurant);
        $session = $this->openSession($table, $owner);

        return [$table, $session, $owner, $rp];
    }

    private function orderAt(Table $table, User $owner, RestaurantProduct $rp, string $status): Order
    {
        $order = $this->createWaiterOrder($table, $owner, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);

        return $status === Order::STATUS_CONFIRMED ? $order : $this->advanceOrderTo($order, $status, $owner);
    }

    private function waitingApprovalOrder(Table $table, RestaurantProduct $rp): Order
    {
        $this->requireOrderApproval($table->restaurant);

        return $this->createCustomerOrder($table, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);
    }

    private function payInFull(TableSession $session, User $owner): void
    {
        $balance = SessionBillCalculator::summarize($session->refresh())['balanceCents'];
        $this->recordPayment($session, $owner, Money::centsToDecimal($balance));
    }

    /**
     * @return array<string, mixed>
     */
    private function stateFromTable(Table $table): array
    {
        return $this->getJson("/api/v1/public/tables/{$table->public_token}")
            ->assertOk()
            ->json('data.session.bill_request');
    }

    /**
     * @return array<string, mixed>
     */
    private function stateFromMenu(Table $table): array
    {
        return $this->getJson("/api/v1/public/tables/{$table->public_token}/menu")
            ->assertOk()
            ->json('data.session.bill_request');
    }

    /**
     * Asserts the exact (and only) shape on BOTH endpoints.
     */
    private function assertState(Table $table, bool $eligible, ?string $reason): void
    {
        $expected = ['eligible' => $eligible, 'reason' => $reason];

        $this->assertSame($expected, $this->stateFromTable($table), 'GET /public/tables/{token}');
        $this->assertSame($expected, $this->stateFromMenu($table), 'GET /public/tables/{token}/menu');
    }

    // --- A/O. No active session -------------------------------------------------------

    public function test_a_table_without_session_is_not_eligible(): void
    {
        [, , $restaurant] = $this->createTenantWithRestaurantProduct();
        $table = $this->createTable($restaurant);
        // An active menu, so GET /menu answers 200 and both endpoints can be compared.
        $this->createMenu($restaurant);

        $this->assertState($table, false, 'no_active_session');
    }

    public function test_o_closed_session_is_not_eligible(): void
    {
        [$table, $session, $owner, $rp] = $this->activeSession();
        $this->orderAt($table, $owner, $rp, Order::STATUS_SERVED);
        $this->payInFull($session, $owner);
        app(CloseTableAction::class)->execute($session->refresh(), $owner);

        $this->assertState($table, false, 'no_active_session');
    }

    // --- B. Nothing consumed ----------------------------------------------------------

    public function test_b_active_session_without_orders_has_no_billable_orders(): void
    {
        [$table] = $this->activeSession();

        $this->assertState($table, false, 'no_billable_orders');
    }

    public function test_only_cancelled_has_no_billable_orders(): void
    {
        [$table, , $owner, $rp] = $this->activeSession();
        app(RejectOrderAction::class)->execute($this->waitingApprovalOrder($table, $rp), $owner);

        $this->assertState($table, false, 'no_billable_orders');
    }

    // --- C–G. Service still in progress -----------------------------------------------

    public function test_c_waiting_approval_is_open_orders(): void
    {
        [$table, , , $rp] = $this->activeSession();
        $this->assertSame(Order::STATUS_WAITING_APPROVAL, $this->waitingApprovalOrder($table, $rp)->status);

        $this->assertState($table, false, 'open_orders');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function openKitchenStatuses(): array
    {
        return [
            'd. confirmed' => [Order::STATUS_CONFIRMED],
            'e. accepted' => [Order::STATUS_ACCEPTED],
            'f. preparing' => [Order::STATUS_PREPARING],
            'g. ready' => [Order::STATUS_READY],
        ];
    }

    #[DataProvider('openKitchenStatuses')]
    public function test_open_kitchen_status_is_open_orders(string $status): void
    {
        [$table, , $owner, $rp] = $this->activeSession();
        $this->assertSame($status, $this->orderAt($table, $owner, $rp, $status)->status);

        $this->assertState($table, false, 'open_orders');
    }

    // --- H–J. Service done ------------------------------------------------------------

    public function test_h_served_is_eligible(): void
    {
        [$table, , $owner, $rp] = $this->activeSession();
        $this->orderAt($table, $owner, $rp, Order::STATUS_SERVED);

        $this->assertState($table, true, null);
    }

    public function test_i_served_plus_preparing_is_open_orders(): void
    {
        [$table, , $owner, $rp] = $this->activeSession();
        $this->orderAt($table, $owner, $rp, Order::STATUS_SERVED);
        $this->orderAt($table, $owner, $rp, Order::STATUS_PREPARING);

        $this->assertState($table, false, 'open_orders');
    }

    public function test_j_served_plus_cancelled_is_eligible(): void
    {
        [$table, , $owner, $rp] = $this->activeSession();
        $this->orderAt($table, $owner, $rp, Order::STATUS_SERVED);
        app(RejectOrderAction::class)->execute($this->waitingApprovalOrder($table, $rp), $owner);

        $this->assertState($table, true, null);
    }

    // --- K–M. Existing request_bill ------------------------------------------------------

    public function test_k_pending_request_bill_is_already_requested(): void
    {
        [$table, , $owner, $rp] = $this->activeSession();
        $this->orderAt($table, $owner, $rp, Order::STATUS_SERVED);
        $this->createTableRequest($table, TableRequest::TYPE_REQUEST_BILL);

        $this->assertState($table, false, 'already_requested');
    }

    public function test_l_acknowledged_request_bill_is_already_requested(): void
    {
        [$table, , $owner, $rp] = $this->activeSession();
        $this->orderAt($table, $owner, $rp, Order::STATUS_SERVED);
        $request = $this->createTableRequest($table, TableRequest::TYPE_REQUEST_BILL);
        $this->advanceTableRequestTo($request, TableRequest::STATUS_ACKNOWLEDGED, $owner);

        $this->assertState($table, false, 'already_requested');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function closedRequestStatuses(): array
    {
        return [
            'm. completed' => [TableRequest::STATUS_COMPLETED],
            'm. cancelled' => [TableRequest::STATUS_CANCELLED],
        ];
    }

    #[DataProvider('closedRequestStatuses')]
    public function test_m_closed_request_bill_makes_the_session_eligible_again(string $status): void
    {
        [$table, , $owner, $rp] = $this->activeSession();
        $this->orderAt($table, $owner, $rp, Order::STATUS_SERVED);
        $request = $this->createTableRequest($table, TableRequest::TYPE_REQUEST_BILL);
        $this->advanceTableRequestTo($request, $status, $owner);

        $this->assertState($table, true, null);
    }

    public function test_an_open_call_waiter_does_not_count_as_a_bill_request(): void
    {
        [$table, , $owner, $rp] = $this->activeSession();
        $this->orderAt($table, $owner, $rp, Order::STATUS_SERVED);
        $this->createTableRequest($table, TableRequest::TYPE_CALL_WAITER);

        $this->assertState($table, true, null);
    }

    public function test_p_staff_order_after_request_bill_stays_already_requested(): void
    {
        // Staff orders are still accepted after a bill request (unchanged);
        // for presentation already_requested outranks open_orders.
        [$table, , $owner, $rp] = $this->activeSession();
        $this->orderAt($table, $owner, $rp, Order::STATUS_SERVED);
        $this->createTableRequest($table, TableRequest::TYPE_REQUEST_BILL);
        $this->assertSame(Order::STATUS_PREPARING, $this->orderAt($table, $owner, $rp, Order::STATUS_PREPARING)->status);

        $this->assertState($table, false, 'already_requested');
    }

    // --- N. Paid -------------------------------------------------------------------------

    public function test_n_paid_active_session_is_already_paid(): void
    {
        [$table, $session, $owner, $rp] = $this->activeSession();
        $this->orderAt($table, $owner, $rp, Order::STATUS_SERVED);
        $this->payInFull($session, $owner);

        $this->assertState($table, false, 'already_paid');
    }

    public function test_paid_outranks_an_open_request_bill(): void
    {
        [$table, $session, $owner, $rp] = $this->activeSession();
        $this->orderAt($table, $owner, $rp, Order::STATUS_SERVED);
        $this->createTableRequest($table, TableRequest::TYPE_REQUEST_BILL);
        $this->payInFull($session, $owner);

        $this->assertState($table, false, 'already_paid');
    }

    // --- Contract --------------------------------------------------------------------------

    public function test_feature_flag_is_not_folded_into_the_session_state(): void
    {
        [$table, , $owner, $rp] = $this->activeSession();
        $this->orderAt($table, $owner, $rp, Order::STATUS_SERVED);
        $table->restaurant->settings()->update(['bill_request_enabled' => false]);

        $this->getJson("/api/v1/public/tables/{$table->public_token}")
            ->assertOk()
            ->assertJsonPath('data.restaurant.capabilities.bill_request', false)
            ->assertJsonPath('data.session.bill_request', ['eligible' => true, 'reason' => null]);
    }

    public function test_only_eligible_and_reason_are_exposed(): void
    {
        [$table, , $owner, $rp] = $this->activeSession();
        $this->orderAt($table, $owner, $rp, Order::STATUS_PREPARING);

        foreach ([$this->stateFromTable($table), $this->stateFromMenu($table)] as $state) {
            $this->assertSame(['eligible', 'reason'], array_keys($state));
        }
    }

    public function test_q_table_and_menu_return_the_same_state_through_the_lifecycle(): void
    {
        // assertState() checks both endpoints against the same expectation.
        [$table, $session, $owner, $rp] = $this->activeSession();
        $this->assertState($table, false, 'no_billable_orders');

        $order = $this->orderAt($table, $owner, $rp, Order::STATUS_PREPARING);
        $this->assertState($table, false, 'open_orders');

        $transition = app(TransitionOrderStatusAction::class);
        $transition->serve($transition->markReady($order, $owner), $owner);
        $this->assertState($table, true, null);

        $this->createTableRequest($table, TableRequest::TYPE_REQUEST_BILL);
        $this->assertState($table, false, 'already_requested');

        $this->payInFull($session, $owner);
        $this->assertState($table, false, 'already_paid');
    }

    // --- Performance -----------------------------------------------------------------------

    public function test_query_count_does_not_grow_with_the_number_of_orders(): void
    {
        [$table, , $owner, $rp] = $this->activeSession();

        $countFor = function (string $url): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson($url)->assertOk();
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        };

        $tableUrl = "/api/v1/public/tables/{$table->public_token}";
        $menuUrl = "{$tableUrl}/menu";

        $this->orderAt($table, $owner, $rp, Order::STATUS_SERVED);
        $tableWithOne = $countFor($tableUrl);
        $menuWithOne = $countFor($menuUrl);

        for ($i = 0; $i < 5; $i++) {
            $this->orderAt($table, $owner, $rp, Order::STATUS_SERVED);
        }
        $this->orderAt($table, $owner, $rp, Order::STATUS_PREPARING);

        $this->assertSame($tableWithOne, $countFor($tableUrl));
        $this->assertSame($menuWithOne, $countFor($menuUrl));
    }
}
