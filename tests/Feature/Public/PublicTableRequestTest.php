<?php

namespace Tests\Feature\Public;

use App\Models\Order;
use App\Models\TableRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithOrders;
use Tests\Concerns\InteractsWithTableRequests;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class PublicTableRequestTest extends TestCase
{
    use InteractsWithOrders, InteractsWithTableRequests, InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
    }

    // --- call_waiter -----------------------------------------------

    public function test_calls_the_waiter_without_authentication(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);

        $response = $this->postJson("/api/v1/public/tables/{$table->public_token}/requests/call-waiter", [
            'note' => 'Necesitamos ayuda con el menú',
        ])->assertStatus(201);

        $response->assertJson(['data' => ['type' => 'call_waiter', 'status' => 'pending']]);
        $this->assertDatabaseCount('table_requests', 1);
    }

    public function test_call_waiter_persists_type_pending_and_no_creator(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);

        $this->postJson("/api/v1/public/tables/{$table->public_token}/requests/call-waiter")->assertStatus(201);

        $tableRequest = TableRequest::query()->firstOrFail();
        $this->assertSame(TableRequest::TYPE_CALL_WAITER, $tableRequest->type);
        $this->assertSame(TableRequest::STATUS_PENDING, $tableRequest->status);
        $this->assertNull($tableRequest->created_by_user_id);
    }

    public function test_content_type_is_json(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);

        $this->postJson("/api/v1/public/tables/{$table->public_token}/requests/call-waiter")
            ->assertHeader('Content-Type', 'application/json');
    }

    public function test_call_waiter_requires_active_session(): void
    {
        [, , $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);

        $response = $this->postJson("/api/v1/public/tables/{$table->public_token}/requests/call-waiter")
            ->assertStatus(409);

        $response->assertJson(['error' => ['code' => 'TABLE_SESSION_NOT_ACTIVE', 'message' => 'The table session is not active.']]);
        $this->assertDatabaseCount('table_requests', 0);
    }

    public function test_call_waiter_frontend_supplied_context_is_ignored(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);

        $this->postJson("/api/v1/public/tables/{$table->public_token}/requests/call-waiter", [
            'restaurant_id' => 999999,
            'table_id' => 999999,
            'table_session_id' => 999999,
            'status' => 'completed',
            'acknowledged_by' => 999999,
        ])->assertStatus(201);

        $tableRequest = TableRequest::query()->firstOrFail();
        $this->assertSame($restaurant->id, $tableRequest->restaurant_id);
        $this->assertSame($table->id, $tableRequest->table_id);
        $this->assertSame(TableRequest::STATUS_PENDING, $tableRequest->status);
        $this->assertNull($tableRequest->acknowledged_by_user_id);
    }

    public function test_inactive_table_returns_public_table_not_found(): void
    {
        [, , $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $table->update(['status' => 'inactive']);

        $this->postJson("/api/v1/public/tables/{$table->public_token}/requests/call-waiter")
            ->assertStatus(404)
            ->assertJson(['error' => ['code' => 'PUBLIC_TABLE_NOT_FOUND']]);
    }

    // --- request_bill --------------------------------------------

    public function test_requests_the_bill_and_creates_pending(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);

        $response = $this->postJson("/api/v1/public/tables/{$table->public_token}/requests/bill")
            ->assertStatus(201);

        $response->assertJson(['data' => ['type' => 'request_bill', 'status' => 'pending']]);
    }

    public function test_request_bill_requires_active_session(): void
    {
        [, , $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);

        $this->postJson("/api/v1/public/tables/{$table->public_token}/requests/bill")
            ->assertStatus(409)
            ->assertJson(['error' => ['code' => 'TABLE_SESSION_NOT_ACTIVE']]);
    }

    public function test_request_bill_does_not_close_the_table_session(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);

        $this->postJson("/api/v1/public/tables/{$table->public_token}/requests/bill")->assertStatus(201);

        $this->assertTrue($session->fresh()->isActive());
    }

    public function test_request_bill_does_not_touch_orders(): void
    {
        [, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);
        $order = $this->createWaiterOrder($table, $owner, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);

        $this->postJson("/api/v1/public/tables/{$table->public_token}/requests/bill")->assertStatus(201);

        $order->refresh();
        $this->assertSame(Order::STATUS_CONFIRMED, $order->status);
    }

    // --- Duplicate prevention -------------------------------------

    public function test_second_open_call_waiter_is_rejected(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);

        $this->postJson("/api/v1/public/tables/{$table->public_token}/requests/call-waiter")->assertStatus(201);

        $response = $this->postJson("/api/v1/public/tables/{$table->public_token}/requests/call-waiter")
            ->assertStatus(409);

        $response->assertJson(['error' => ['code' => 'TABLE_REQUEST_ALREADY_OPEN']]);
        $this->assertDatabaseCount('table_requests', 1);
    }

    public function test_second_open_request_bill_is_rejected(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);

        $this->postJson("/api/v1/public/tables/{$table->public_token}/requests/bill")->assertStatus(201);
        $this->postJson("/api/v1/public/tables/{$table->public_token}/requests/bill")->assertStatus(409);
    }

    public function test_call_waiter_and_request_bill_can_both_be_open_simultaneously(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);

        $this->postJson("/api/v1/public/tables/{$table->public_token}/requests/call-waiter")->assertStatus(201);
        $this->postJson("/api/v1/public/tables/{$table->public_token}/requests/bill")->assertStatus(201);

        $this->assertDatabaseCount('table_requests', 2);
    }

    public function test_new_request_allowed_after_previous_one_completed(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);

        $first = $this->createTableRequest($table, TableRequest::TYPE_CALL_WAITER);
        $this->advanceTableRequestTo($first, TableRequest::STATUS_COMPLETED, $owner);

        $this->postJson("/api/v1/public/tables/{$table->public_token}/requests/call-waiter")->assertStatus(201);

        $this->assertDatabaseCount('table_requests', 2);
    }

    public function test_new_request_allowed_after_previous_one_cancelled(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);

        $first = $this->createTableRequest($table, TableRequest::TYPE_CALL_WAITER);
        $this->advanceTableRequestTo($first, TableRequest::STATUS_CANCELLED, $owner);

        $this->postJson("/api/v1/public/tables/{$table->public_token}/requests/call-waiter")->assertStatus(201);

        $this->assertDatabaseCount('table_requests', 2);
    }

    public function test_two_sequential_creates_only_the_first_succeeds(): void
    {
        // Sequential stand-in for the concurrent double-tap case: the
        // partial unique index on (table_session_id, type) WHERE status IN
        // (pending, acknowledged) is the real guard — this exercises the
        // application-level mapping of that DB conflict to a friendly 409.
        [, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);

        $this->postJson("/api/v1/public/tables/{$table->public_token}/requests/call-waiter")->assertStatus(201);
        $this->postJson("/api/v1/public/tables/{$table->public_token}/requests/call-waiter")->assertStatus(409);
    }

    // --- Validation ---------------------------------------------------

    public function test_oversized_note_is_rejected(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);

        $this->postJson("/api/v1/public/tables/{$table->public_token}/requests/call-waiter", [
            'note' => str_repeat('a', 501),
        ])->assertStatus(422);
    }

    // --- Public resource contract -----------------------------------

    public function test_public_response_contains_only_id_type_status_created_at(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);

        $response = $this->postJson("/api/v1/public/tables/{$table->public_token}/requests/call-waiter")
            ->assertStatus(201);

        $this->assertEqualsCanonicalizing(['id', 'type', 'status', 'created_at'], array_keys($response->json('data')));
    }
}
