<?php

namespace Tests\Feature\Realtime;

use App\Actions\Tables\AssignWaiterAction;
use App\Actions\Tables\TransferTableSessionAction;
use App\Actions\Tables\UnassignWaiterAction;
use App\Events\Realtime\TableSessionClosed;
use App\Events\Realtime\TableSessionOpened;
use App\Events\Realtime\TableSessionTransferred;
use App\Events\Realtime\WaiterAssigned;
use App\Events\Realtime\WaiterReassigned;
use App\Events\Realtime\WaiterUnassigned;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\InteractsWithOrders;
use Tests\Concerns\InteractsWithPayments;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bloco 7 — realtime events for OpenTableAction/CloseTableAction/
 * TransferTableSessionAction/AssignWaiterAction/UnassignWaiterAction.
 */
class TableSessionEventsTest extends TestCase
{
    use InteractsWithOrders, InteractsWithPayments, InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
    }

    public function test_opening_a_table_dispatches_table_session_opened_with_a_small_payload(): void
    {
        Event::fake([TableSessionOpened::class]);

        [, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);

        $session = $this->openSession($table, $owner, 4);

        Event::assertDispatched(TableSessionOpened::class, function (TableSessionOpened $event) use ($restaurant, $table, $session) {
            return $event->restaurantId === $restaurant->id
                && $event->tableId === $table->id
                && $event->tableSessionId === $session->id
                && $event->guestCount === 4
                && $event->broadcastAs() === 'table.session.opened';
        });
    }

    public function test_closing_a_table_dispatches_table_session_closed(): void
    {
        Event::fake([TableSessionClosed::class]);

        [, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);
        $closed = $this->closeSessionWithFullPayment($session, $owner);

        Event::assertDispatched(TableSessionClosed::class, function (TableSessionClosed $event) use ($restaurant, $table, $closed) {
            return $event->restaurantId === $restaurant->id
                && $event->tableId === $table->id
                && $event->tableSessionId === $closed->id
                && $event->broadcastAs() === 'table.session.closed';
        });
    }

    public function test_transferring_a_session_dispatches_table_session_transferred(): void
    {
        Event::fake([TableSessionTransferred::class]);

        [, $owner, $restaurant] = $this->createTenant();
        $fromTable = $this->createTable($restaurant);
        $toTable = $this->createTable($restaurant);
        $session = $this->openSession($fromTable, $owner);

        app(TransferTableSessionAction::class)->execute($session, $toTable, $owner);

        Event::assertDispatched(TableSessionTransferred::class, function (TableSessionTransferred $event) use ($restaurant, $session, $fromTable, $toTable) {
            return $event->restaurantId === $restaurant->id
                && $event->tableSessionId === $session->id
                && $event->fromTableId === $fromTable->id
                && $event->toTableId === $toTable->id
                && $event->broadcastAs() === 'table.session.transferred';
        });
    }

    public function test_assigning_a_waiter_for_the_first_time_dispatches_assigned_not_reassigned(): void
    {
        Event::fake([WaiterAssigned::class, WaiterReassigned::class]);

        [$organization, $owner, $restaurant] = $this->createTenant();
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);

        app(AssignWaiterAction::class)->execute($session, $waiter, $owner);

        Event::assertDispatched(WaiterAssigned::class, function (WaiterAssigned $event) use ($restaurant, $session, $table, $waiter) {
            return $event->restaurantId === $restaurant->id
                && $event->tableSessionId === $session->id
                && $event->tableId === $table->id
                && $event->previousWaiterUserId === null
                && $event->newWaiterUserId === $waiter->id
                && $event->broadcastAs() === 'table.waiter.assigned';
        });
        Event::assertNotDispatched(WaiterReassigned::class);
    }

    public function test_reassigning_a_waiter_dispatches_reassigned_not_assigned(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $waiterA = $this->createStaff($organization, $restaurant, 'waiter', 'W-A');
        $waiterB = $this->createStaff($organization, $restaurant, 'waiter', 'W-B');
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);
        app(AssignWaiterAction::class)->execute($session, $waiterA, $owner);

        Event::fake([WaiterAssigned::class, WaiterReassigned::class]);

        app(AssignWaiterAction::class)->execute($session, $waiterB, $owner);

        Event::assertDispatched(WaiterReassigned::class, function (WaiterReassigned $event) use ($waiterA, $waiterB) {
            return $event->previousWaiterUserId === $waiterA->id
                && $event->newWaiterUserId === $waiterB->id
                && $event->broadcastAs() === 'table.waiter.reassigned';
        });
        Event::assertNotDispatched(WaiterAssigned::class);
    }

    public function test_assigning_the_same_waiter_again_is_a_no_op_and_broadcasts_nothing(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);
        app(AssignWaiterAction::class)->execute($session, $waiter, $owner);

        Event::fake([WaiterAssigned::class, WaiterReassigned::class]);

        app(AssignWaiterAction::class)->execute($session, $waiter, $owner);

        Event::assertNotDispatched(WaiterAssigned::class);
        Event::assertNotDispatched(WaiterReassigned::class);
    }

    public function test_unassigning_a_waiter_dispatches_waiter_unassigned(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);
        app(AssignWaiterAction::class)->execute($session, $waiter, $owner);

        Event::fake([WaiterUnassigned::class]);

        app(UnassignWaiterAction::class)->execute($session, $owner);

        Event::assertDispatched(WaiterUnassigned::class, function (WaiterUnassigned $event) use ($waiter) {
            return $event->previousWaiterUserId === $waiter->id
                && $event->newWaiterUserId === null
                && $event->broadcastAs() === 'table.waiter.unassigned';
        });
    }
}
