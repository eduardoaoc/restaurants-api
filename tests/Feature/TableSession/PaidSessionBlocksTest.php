<?php

namespace Tests\Feature\TableSession;

use App\Actions\Tables\CloseTableAction;
use App\Events\Realtime\TableRequestCreated;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\RestaurantProduct;
use App\Models\Table;
use App\Models\TableRequest;
use App\Models\TableSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
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

    // --- TableRequests after payment (CARTA 5.1C) -------------------
    //
    // call_waiter is not a financial operation: a paid-but-still-active
    // session may still call the waiter (post-payment "want anything
    // else?" CTA). request_bill, and anything on a closed session, stays
    // blocked.

    public function test_public_call_waiter_is_allowed_after_session_is_paid_while_still_active(): void
    {
        [$table, $session] = $this->paidSession();

        $this->postJson("/api/v1/public/tables/{$table->public_token}/requests/call-waiter")
            ->assertStatus(201)
            ->assertJson(['data' => ['type' => TableRequest::TYPE_CALL_WAITER, 'status' => TableRequest::STATUS_PENDING]]);

        $request = TableRequest::query()->sole();
        $this->assertSame($session->id, $request->table_session_id);
        $this->assertSame(TableRequest::TYPE_CALL_WAITER, $request->type);
        $this->assertSame(TableRequest::STATUS_PENDING, $request->status);
        $this->assertTrue($session->refresh()->isPaid());
        $this->assertTrue($session->isActive());
    }

    public function test_paid_call_waiter_still_respects_waiter_call_disabled(): void
    {
        [$table] = $this->paidSession();
        $table->restaurant->settings()->update(['waiter_call_enabled' => false]);

        $this->postJson("/api/v1/public/tables/{$table->public_token}/requests/call-waiter")
            ->assertStatus(409)
            ->assertJson(['error' => ['code' => 'WAITER_CALL_DISABLED']]);

        $this->assertDatabaseCount('table_requests', 0);
    }

    public function test_paid_call_waiter_is_rejected_while_another_call_waiter_is_open(): void
    {
        [$table] = $this->paidSession();
        $this->createTableRequest($table, TableRequest::TYPE_CALL_WAITER);

        $this->postJson("/api/v1/public/tables/{$table->public_token}/requests/call-waiter")
            ->assertStatus(409)
            ->assertJson(['error' => ['code' => 'TABLE_REQUEST_ALREADY_OPEN']]);

        $this->assertDatabaseCount('table_requests', 1);
    }

    public function test_paid_call_waiter_is_allowed_again_after_the_previous_one_completed(): void
    {
        [$table, , $owner] = $this->paidSession();
        $first = $this->createTableRequest($table, TableRequest::TYPE_CALL_WAITER);
        $this->advanceTableRequestTo($first, TableRequest::STATUS_COMPLETED, $owner);

        $this->postJson("/api/v1/public/tables/{$table->public_token}/requests/call-waiter")
            ->assertStatus(201);

        $this->assertDatabaseCount('table_requests', 2);
    }

    public function test_public_request_bill_is_blocked_after_session_is_paid(): void
    {
        [$table] = $this->paidSession();

        $this->postJson("/api/v1/public/tables/{$table->public_token}/requests/bill")
            ->assertStatus(409)
            ->assertJson(['error' => ['code' => 'TABLE_SESSION_ALREADY_PAID']]);

        $this->assertSame(0, TableRequest::query()->where('type', TableRequest::TYPE_REQUEST_BILL)->count());
    }

    public function test_call_waiter_on_a_paid_and_closed_session_is_rejected_as_not_active(): void
    {
        [$table, $session, $owner] = $this->paidSession();
        app(CloseTableAction::class)->execute($session, $owner);

        $this->postJson("/api/v1/public/tables/{$table->public_token}/requests/call-waiter")
            ->assertStatus(409)
            ->assertJson(['error' => ['code' => 'TABLE_SESSION_NOT_ACTIVE']]);

        $this->assertDatabaseCount('table_requests', 0);
    }

    public function test_request_bill_on_a_paid_and_closed_session_is_rejected_as_not_active(): void
    {
        [$table, $session, $owner] = $this->paidSession();
        app(CloseTableAction::class)->execute($session, $owner);

        $this->postJson("/api/v1/public/tables/{$table->public_token}/requests/bill")
            ->assertStatus(409)
            ->assertJson(['error' => ['code' => 'TABLE_SESSION_NOT_ACTIVE']]);

        $this->assertDatabaseCount('table_requests', 0);
    }

    public function test_paid_call_waiter_goes_through_the_normal_audit_and_realtime_flow(): void
    {
        [$table, $session] = $this->paidSession();
        Event::fake([TableRequestCreated::class]);

        $this->postJson("/api/v1/public/tables/{$table->public_token}/requests/call-waiter")
            ->assertStatus(201);

        $request = TableRequest::query()->sole();

        $log = AuditLog::query()->where('event', AuditLog::EVENT_TABLE_REQUEST_CREATED)->sole();
        $this->assertSame(AuditLog::ACTOR_PUBLIC, $log->actor_type);
        $this->assertSame(AuditLog::RESOURCE_TABLE_REQUEST, $log->resource_type);
        $this->assertSame($request->id, $log->resource_id);

        Event::assertDispatched(TableRequestCreated::class, fn (TableRequestCreated $event) => $event->tableSessionId === $session->id
            && $event->tableRequestId === $request->id
            && $event->type === TableRequest::TYPE_CALL_WAITER
            && $event->status === TableRequest::STATUS_PENDING);
    }

    public function test_paid_call_waiter_does_not_reopen_ordering(): void
    {
        [$table, , , $rp] = $this->paidSession();

        $this->postJson("/api/v1/public/tables/{$table->public_token}/requests/call-waiter")
            ->assertStatus(201);

        $this->postJson("/api/v1/public/tables/{$table->public_token}/orders", [
            'items' => [['restaurant_product_id' => $rp->id, 'quantity' => 1]],
        ])->assertStatus(409)->assertJson(['error' => ['code' => 'TABLE_SESSION_ALREADY_PAID']]);

        $this->assertSame(1, Order::query()->count());
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
