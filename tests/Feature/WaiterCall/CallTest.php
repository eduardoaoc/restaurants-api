<?php

namespace Tests\Feature\WaiterCall;

use App\Actions\Billing\RecordPaymentAction;
use App\Actions\Tables\AcknowledgeWaiterCallAction;
use App\Actions\Tables\AssignWaiterAction;
use App\Actions\Tables\CallResponsibleWaiterAction;
use App\Actions\Tables\CloseTableAction;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithOrders;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bloco 4 — "call responsible waiter": happy path, no-assigned-waiter
 * rejection, suspended-waiter rejection, duplicate pending call, closed
 * session, and acknowledge.
 */
class CallTest extends TestCase
{
    use InteractsWithOrders, InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    public function test_owner_calls_the_assigned_waiter(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1', name: 'Mateo');
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);
        app(AssignWaiterAction::class)->execute($session, $waiter, $owner);

        $this->actingAs($owner, 'web')
            ->postJson("/api/v1/table-sessions/{$session->id}/waiter-calls")
            ->assertCreated()
            ->assertJsonPath('data.waiter_call.status', 'pending')
            ->assertJsonPath('data.waiter_call.waiter.id', $waiter->id)
            ->assertJsonPath('data.waiter_call.waiter.name', 'Mateo')
            ->assertJsonPath('data.waiter_call.called_by.id', $owner->id);

        $this->assertDatabaseHas('waiter_calls', [
            'table_session_id' => $session->id,
            'waiter_user_id' => $waiter->id,
            'called_by_user_id' => $owner->id,
            'status' => 'pending',
        ]);
    }

    public function test_manager_with_permission_calls_the_assigned_waiter(): void
    {
        [$organization, , $restaurant] = $this->createTenant();
        $manager = $this->createStaff($organization, $restaurant, 'manager', 'M-1');
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $manager);
        app(AssignWaiterAction::class)->execute($session, $waiter, $manager);

        $this->actingAs($manager, 'web')
            ->postJson("/api/v1/table-sessions/{$session->id}/waiter-calls")
            ->assertCreated();
    }

    public function test_calling_without_an_assigned_waiter_returns_conflict(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);

        $this->actingAs($owner, 'web')
            ->postJson("/api/v1/table-sessions/{$session->id}/waiter-calls")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'TABLE_SESSION_HAS_NO_ASSIGNED_WAITER');

        $this->assertDatabaseCount('waiter_calls', 0);
    }

    public function test_calling_a_suspended_assigned_waiter_returns_conflict(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);
        app(AssignWaiterAction::class)->execute($session, $waiter, $owner);
        $waiter->update(['status' => User::STATUS_SUSPENDED]);

        $this->actingAs($owner, 'web')
            ->postJson("/api/v1/table-sessions/{$session->id}/waiter-calls")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'TABLE_SESSION_HAS_NO_ASSIGNED_WAITER');

        $this->assertDatabaseCount('waiter_calls', 0);
    }

    public function test_duplicate_pending_call_returns_conflict(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);
        app(AssignWaiterAction::class)->execute($session, $waiter, $owner);

        $this->actingAs($owner, 'web')
            ->postJson("/api/v1/table-sessions/{$session->id}/waiter-calls")
            ->assertCreated();

        $this->actingAs($owner, 'web')
            ->postJson("/api/v1/table-sessions/{$session->id}/waiter-calls")
            ->assertStatus(409);

        $this->assertDatabaseCount('waiter_calls', 1);
    }

    public function test_calling_on_a_closed_session_returns_conflict(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);
        app(AssignWaiterAction::class)->execute($session, $waiter, $owner);

        $restaurantProduct = $this->createRestaurantProduct($restaurant, $this->createProduct($organization), 10.0);
        $order = $this->createWaiterOrder($table, $owner, [
            ['restaurant_product_id' => $restaurantProduct->id, 'quantity' => 1],
        ]);
        $order = $this->advanceOrderTo($order, Order::STATUS_SERVED, $owner);
        app(RecordPaymentAction::class)->execute($session, $owner, ['method' => 'cash', 'amount' => $order->total]);
        app(CloseTableAction::class)->execute($session, $owner);

        $this->actingAs($owner, 'web')
            ->postJson("/api/v1/table-sessions/{$session->id}/waiter-calls")
            ->assertStatus(409);
    }

    public function test_waiter_acknowledges_their_own_call(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);
        app(AssignWaiterAction::class)->execute($session, $waiter, $owner);

        // Placed via the real Action, not HTTP — this test's one HTTP call
        // (below) is the waiter's own, matching this project's convention
        // of one acting HTTP user per test (see the Bloco 3 report on
        // Sanctum's AuthenticateSession and chained actingAs()).
        $call = app(CallResponsibleWaiterAction::class)->execute($session, $owner);

        $this->actingAs($waiter, 'web')
            ->postJson("/api/v1/waiter-calls/{$call->id}/acknowledge")
            ->assertOk()
            ->assertJsonPath('data.waiter_call.status', 'acknowledged')
            ->assertJsonPath('data.waiter_call.acknowledged_by.id', $waiter->id);
    }

    public function test_acknowledging_an_already_acknowledged_call_returns_conflict(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);
        app(AssignWaiterAction::class)->execute($session, $waiter, $owner);
        $call = app(CallResponsibleWaiterAction::class)->execute($session, $owner);
        app(AcknowledgeWaiterCallAction::class)->execute($call, $waiter);

        $this->actingAs($waiter, 'web')
            ->postJson("/api/v1/waiter-calls/{$call->id}/acknowledge")
            ->assertStatus(409);
    }

    public function test_a_new_call_can_be_placed_after_the_previous_one_was_acknowledged(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);
        app(AssignWaiterAction::class)->execute($session, $waiter, $owner);
        $firstCall = app(CallResponsibleWaiterAction::class)->execute($session, $owner);
        app(AcknowledgeWaiterCallAction::class)->execute($firstCall, $waiter);

        $this->actingAs($owner, 'web')
            ->postJson("/api/v1/table-sessions/{$session->id}/waiter-calls")
            ->assertCreated();

        $this->assertDatabaseCount('waiter_calls', 2);
    }
}
