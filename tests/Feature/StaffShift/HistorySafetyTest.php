<?php

namespace Tests\Feature\StaffShift;

use App\Actions\Tables\AssignWaiterAction;
use App\Models\TableRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithOrders;
use Tests\Concerns\InteractsWithStaffShifts;
use Tests\Concerns\InteractsWithTableRequests;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bloco 3 — starting/ending a shift must never touch TableSession, Waiter
 * Assignment, Orders, Payments, or TableRequests. No cascading side
 * effects — see the Bloco 3 report on the "waiter ends shift while still
 * assigned to tables" open decision.
 */
class HistorySafetyTest extends TestCase
{
    use InteractsWithOrders, InteractsWithStaffShifts, InteractsWithTableRequests, InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    public function test_ending_a_shift_does_not_touch_table_session_assignment_orders_or_requests(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);

        // Setup only — done via the real Action, not HTTP, so this test's
        // one HTTP call (below) is the waiter's own, matching this
        // project's convention of one acting HTTP user per test (see
        // OrderLifecycleTransitionTest::advanceOrderTo for the same
        // pattern with Orders).
        app(AssignWaiterAction::class)->execute($session, $waiter, $owner);

        $restaurantProduct = $this->createRestaurantProduct($restaurant, $this->createProduct($organization), 10.0);
        $order = $this->createWaiterOrder($table, $waiter, [
            ['restaurant_product_id' => $restaurantProduct->id, 'quantity' => 1],
        ]);
        $tableRequest = $this->createTableRequest($table, TableRequest::TYPE_CALL_WAITER);

        $shift = $this->startShift($restaurant, $waiter, $waiter);

        $this->actingAs($waiter, 'web')
            ->postJson("/api/v1/staff-shifts/{$shift->id}/end")
            ->assertOk();

        $this->assertSame('occupied', $session->fresh()->status);
        $this->assertSame($waiter->id, $session->fresh()->assigned_waiter_user_id);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'table_session_id' => $session->id]);
        $this->assertDatabaseHas('table_requests', ['id' => $tableRequest->id, 'table_session_id' => $session->id]);
        $this->assertSame($table->id, $session->fresh()->table_id);
    }

    public function test_starting_a_shift_does_not_create_or_alter_any_table_session(): void
    {
        [$organization, , $restaurant] = $this->createTenant();
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');

        $this->startShift($restaurant, $waiter, $waiter);

        $this->assertDatabaseCount('table_sessions', 0);
    }
}
