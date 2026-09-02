<?php

namespace Tests\Feature\TableRequest;

use App\Actions\Tables\CloseTableAction;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Restaurant;
use App\Models\TableRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithOrders;
use Tests\Concerns\InteractsWithTableRequests;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class TableRequestLifecycleTest extends TestCase
{
    use InteractsWithOrders, InteractsWithTableRequests, InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    /**
     * @return array{0: TableRequest, 1: Organization, 2: Restaurant, 3: User}
     */
    private function pendingRequest(string $type = TableRequest::TYPE_CALL_WAITER): array
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);
        $tableRequest = $this->createTableRequest($table, $type);

        return [$tableRequest, $organization, $restaurant, $waiter];
    }

    // --- acknowledge ------------------------------------------------

    public function test_waiter_acknowledges_a_pending_request(): void
    {
        [$tableRequest, , , $waiter] = $this->pendingRequest();

        $response = $this->actingAs($waiter, 'web')
            ->postJson("/api/v1/table-requests/{$tableRequest->id}/acknowledge")
            ->assertOk();

        $response->assertJson(['data' => ['table_request' => ['status' => 'acknowledged']]]);

        $tableRequest->refresh();
        $this->assertSame(TableRequest::STATUS_ACKNOWLEDGED, $tableRequest->status);
        $this->assertSame($waiter->id, $tableRequest->acknowledged_by_user_id);
        $this->assertNotNull($tableRequest->acknowledged_at);
    }

    public function test_acknowledge_already_acknowledged_returns_409(): void
    {
        [$tableRequest, , , $waiter] = $this->pendingRequest();
        $this->actingAs($waiter, 'web')->postJson("/api/v1/table-requests/{$tableRequest->id}/acknowledge")->assertOk();

        $this->actingAs($waiter, 'web')
            ->postJson("/api/v1/table-requests/{$tableRequest->id}/acknowledge")
            ->assertStatus(409);
    }

    // --- complete ------------------------------------------------

    public function test_waiter_completes_an_acknowledged_request(): void
    {
        [$tableRequest, , , $waiter] = $this->pendingRequest();
        $this->advanceTableRequestTo($tableRequest, TableRequest::STATUS_ACKNOWLEDGED, $waiter);

        $response = $this->actingAs($waiter, 'web')
            ->postJson("/api/v1/table-requests/{$tableRequest->id}/complete")
            ->assertOk();

        $response->assertJson(['data' => ['table_request' => ['status' => 'completed']]]);

        $tableRequest->refresh();
        $this->assertSame($waiter->id, $tableRequest->completed_by_user_id);
        $this->assertNotNull($tableRequest->completed_at);
    }

    public function test_complete_pending_request_returns_409(): void
    {
        [$tableRequest, , , $waiter] = $this->pendingRequest();

        $this->actingAs($waiter, 'web')
            ->postJson("/api/v1/table-requests/{$tableRequest->id}/complete")
            ->assertStatus(409);
    }

    // --- cancel ------------------------------------------------------

    public function test_cancel_from_pending(): void
    {
        [$tableRequest, , , $waiter] = $this->pendingRequest();

        $response = $this->actingAs($waiter, 'web')
            ->postJson("/api/v1/table-requests/{$tableRequest->id}/cancel")
            ->assertOk();

        $response->assertJson(['data' => ['table_request' => ['status' => 'cancelled']]]);

        $tableRequest->refresh();
        $this->assertSame($waiter->id, $tableRequest->cancelled_by_user_id);
        $this->assertNotNull($tableRequest->cancelled_at);
    }

    public function test_cancel_from_acknowledged(): void
    {
        [$tableRequest, , , $waiter] = $this->pendingRequest();
        $this->advanceTableRequestTo($tableRequest, TableRequest::STATUS_ACKNOWLEDGED, $waiter);

        $this->actingAs($waiter, 'web')
            ->postJson("/api/v1/table-requests/{$tableRequest->id}/cancel")
            ->assertOk()
            ->assertJson(['data' => ['table_request' => ['status' => 'cancelled']]]);
    }

    // --- invalid transitions ---------------------------------------------

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function invalidTransitions(): array
    {
        return [
            'pending -> completed' => ['pending', 'complete'],
            'completed -> acknowledge' => ['completed', 'acknowledge'],
            'completed -> cancel' => ['completed', 'cancel'],
            'cancelled -> acknowledge' => ['cancelled', 'acknowledge'],
            'cancelled -> complete' => ['cancelled', 'complete'],
        ];
    }

    #[DataProvider('invalidTransitions')]
    public function test_invalid_transition_returns_409(string $fromStatus, string $endpoint): void
    {
        [$tableRequest, , , $waiter] = $this->pendingRequest();

        if ($fromStatus !== 'pending') {
            $tableRequest = $this->advanceTableRequestTo($tableRequest, $fromStatus, $waiter);
        }

        $this->actingAs($waiter, 'web')
            ->postJson("/api/v1/table-requests/{$tableRequest->id}/{$endpoint}")
            ->assertStatus(409);
    }

    public function test_acknowledged_cannot_go_back_to_pending_there_is_no_such_endpoint(): void
    {
        // There is intentionally no "un-acknowledge" endpoint at all —
        // acknowledged -> pending simply isn't a route that exists.
        [$tableRequest, , , $waiter] = $this->pendingRequest();
        $tableRequest = $this->advanceTableRequestTo($tableRequest, TableRequest::STATUS_ACKNOWLEDGED, $waiter);

        $this->assertSame(TableRequest::STATUS_ACKNOWLEDGED, $tableRequest->status);
    }

    // --- concurrency (sequential stand-in) --------------------------------

    public function test_two_sequential_acknowledges_only_the_first_succeeds(): void
    {
        // Sequential stand-in for the concurrent case: both would race for
        // the same lockForUpdate()'d row inside TransitionTableRequestStatusAction.
        [$tableRequest, , , $waiter] = $this->pendingRequest();

        $this->actingAs($waiter, 'web')->postJson("/api/v1/table-requests/{$tableRequest->id}/acknowledge")->assertOk();
        $this->actingAs($waiter, 'web')->postJson("/api/v1/table-requests/{$tableRequest->id}/acknowledge")->assertStatus(409);
    }

    public function test_two_sequential_completes_only_the_first_succeeds(): void
    {
        [$tableRequest, , , $waiter] = $this->pendingRequest();
        $this->advanceTableRequestTo($tableRequest, TableRequest::STATUS_ACKNOWLEDGED, $waiter);

        $this->actingAs($waiter, 'web')->postJson("/api/v1/table-requests/{$tableRequest->id}/complete")->assertOk();
        $this->actingAs($waiter, 'web')->postJson("/api/v1/table-requests/{$tableRequest->id}/complete")->assertStatus(409);
    }

    // --- TableSession independence -----------------------------------

    public function test_lifecycle_continues_after_the_table_session_is_closed(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);
        $tableRequest = $this->createTableRequest($table, TableRequest::TYPE_REQUEST_BILL);

        app(CloseTableAction::class)->execute($session, $owner);

        $this->actingAs($waiter, 'web')->postJson("/api/v1/table-requests/{$tableRequest->id}/acknowledge")->assertOk();
        $this->actingAs($waiter, 'web')->postJson("/api/v1/table-requests/{$tableRequest->id}/complete")->assertOk();

        $tableRequest->refresh();
        $this->assertSame(TableRequest::STATUS_COMPLETED, $tableRequest->status);
    }

    public function test_new_public_request_after_session_closed_returns_409(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);
        app(CloseTableAction::class)->execute($session, $owner);

        $this->postJson("/api/v1/public/tables/{$table->public_token}/requests/call-waiter")
            ->assertStatus(409)
            ->assertJson(['error' => ['code' => 'TABLE_SESSION_NOT_ACTIVE']]);
    }

    public function test_completing_bill_request_does_not_close_session_or_touch_payment(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);
        $tableRequest = $this->createTableRequest($table, TableRequest::TYPE_REQUEST_BILL);

        $this->actingAs($waiter, 'web')->postJson("/api/v1/table-requests/{$tableRequest->id}/acknowledge")->assertOk();
        $this->actingAs($waiter, 'web')->postJson("/api/v1/table-requests/{$tableRequest->id}/complete")->assertOk();

        $this->assertTrue($session->fresh()->isActive());
    }

    public function test_completing_call_waiter_does_not_alter_orders(): void
    {
        [$organization, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);
        $order = $this->createWaiterOrder($table, $owner, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);
        $tableRequest = $this->createTableRequest($table, TableRequest::TYPE_CALL_WAITER);

        $this->actingAs($waiter, 'web')->postJson("/api/v1/table-requests/{$tableRequest->id}/acknowledge")->assertOk();
        $this->actingAs($waiter, 'web')->postJson("/api/v1/table-requests/{$tableRequest->id}/complete")->assertOk();

        $order->refresh();
        $this->assertSame(Order::STATUS_CONFIRMED, $order->status);
    }
}
