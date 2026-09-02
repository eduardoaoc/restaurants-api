<?php

namespace Tests\Feature\TableSession;

use App\Models\Order;
use App\Models\RestaurantProduct;
use App\Models\Table;
use App\Models\TableRequest;
use App\Models\TableSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithOrders;
use Tests\Concerns\InteractsWithPayments;
use Tests\Concerns\InteractsWithTableRequests;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class PaidSessionBlocksTest extends TestCase
{
    use InteractsWithOrders, InteractsWithPayments, InteractsWithTableRequests, InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    /**
     * @return array{0: Table, 1: TableSession, 2: User, 3: RestaurantProduct}
     */
    private function paidSession(): array
    {
        [, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);
        $order = $this->createWaiterOrder($table, $owner, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);
        $this->advanceOrderTo($order, Order::STATUS_SERVED, $owner);
        $this->recordPayment($session, $owner, $order->total);

        return [$table, $session, $owner, $rp];
    }

    // --- Orders blocked -------------------------------------------------

    public function test_public_order_is_blocked_after_session_is_paid(): void
    {
        [$table, , , $rp] = $this->paidSession();

        $response = $this->postJson("/api/v1/public/tables/{$table->public_token}/orders", [
            'items' => [['restaurant_product_id' => $rp->id, 'quantity' => 1]],
        ])->assertStatus(409);

        $response->assertJson(['error' => ['code' => 'TABLE_SESSION_ALREADY_PAID']]);
    }

    public function test_waiter_order_is_blocked_after_session_is_paid(): void
    {
        [$table, , $owner, $rp] = $this->paidSession();

        $response = $this->actingAs($owner, 'web')
            ->postJson("/api/v1/tables/{$table->id}/orders", [
                'items' => [['restaurant_product_id' => $rp->id, 'quantity' => 1]],
            ])->assertStatus(409);

        $response->assertJson(['error' => ['code' => 'TABLE_SESSION_ALREADY_PAID']]);
    }

    // --- TableRequests blocked --------------------------------------

    public function test_public_call_waiter_is_blocked_after_session_is_paid(): void
    {
        [$table] = $this->paidSession();

        $this->postJson("/api/v1/public/tables/{$table->public_token}/requests/call-waiter")
            ->assertStatus(409)
            ->assertJson(['error' => ['code' => 'TABLE_SESSION_ALREADY_PAID']]);
    }

    public function test_public_request_bill_is_blocked_after_session_is_paid(): void
    {
        [$table] = $this->paidSession();

        $this->postJson("/api/v1/public/tables/{$table->public_token}/requests/bill")
            ->assertStatus(409)
            ->assertJson(['error' => ['code' => 'TABLE_SESSION_ALREADY_PAID']]);
    }

    // --- Existing TableRequests remain operable ---------------------

    public function test_existing_table_request_can_still_be_acknowledged_and_completed_after_paid(): void
    {
        [, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);
        $order = $this->createWaiterOrder($table, $owner, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);
        $this->advanceOrderTo($order, Order::STATUS_SERVED, $owner);

        // Request created before payment, exactly like the pre-existing
        // Orders scenario the report documents.
        $tableRequest = $this->createTableRequest($table, TableRequest::TYPE_CALL_WAITER);

        $this->recordPayment($session, $owner, $order->total);

        $this->actingAs($owner, 'web')
            ->postJson("/api/v1/table-requests/{$tableRequest->id}/acknowledge")
            ->assertOk();

        $this->actingAs($owner, 'web')
            ->postJson("/api/v1/table-requests/{$tableRequest->id}/complete")
            ->assertOk();

        $tableRequest->refresh();
        $this->assertSame(TableRequest::STATUS_COMPLETED, $tableRequest->status);
    }

    // --- Public Menu unaffected -------------------------------------

    public function test_public_menu_still_readable_after_session_is_paid(): void
    {
        [$table] = $this->paidSession();

        $this->getJson("/api/v1/public/tables/{$table->public_token}")->assertOk();
    }
}
