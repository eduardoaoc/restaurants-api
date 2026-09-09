<?php

namespace Tests\Feature\Realtime;

use App\Actions\TableRequests\TransitionTableRequestStatusAction;
use App\Actions\Tables\AcknowledgeWaiterCallAction;
use App\Actions\Tables\AssignWaiterAction;
use App\Actions\Tables\CallResponsibleWaiterAction;
use App\Events\Realtime\TableRequestAcknowledged;
use App\Events\Realtime\TableRequestCreated;
use App\Events\Realtime\WaiterCallAcknowledged;
use App\Events\Realtime\WaiterCallCreated;
use App\Models\TableRequest;
use App\Models\WaiterCall;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\InteractsWithOrders;
use Tests\Concerns\InteractsWithTableRequests;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bloco 7 — realtime events for the two customer-originated TableRequest
 * transitions (created/acknowledged) and the two internal WaiterCall
 * transitions (created/acknowledged) — deliberately separate domains, see
 * routes/channels.php and the two events' own docblocks.
 */
class TableRequestAndWaiterCallEventsTest extends TestCase
{
    use InteractsWithOrders, InteractsWithTableRequests, InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
    }

    public function test_creating_a_table_request_dispatches_table_request_created(): void
    {
        Event::fake([TableRequestCreated::class]);

        [, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);

        $request = $this->createTableRequest($table, TableRequest::TYPE_CALL_WAITER);

        Event::assertDispatched(TableRequestCreated::class, function (TableRequestCreated $event) use ($restaurant, $table, $session, $request) {
            return $event->restaurantId === $restaurant->id
                && $event->tableId === $table->id
                && $event->tableSessionId === $session->id
                && $event->tableRequestId === $request->id
                && $event->type === TableRequest::TYPE_CALL_WAITER
                && $event->status === TableRequest::STATUS_PENDING
                && $event->broadcastAs() === 'table_request.created';
        });
    }

    public function test_acknowledging_a_table_request_dispatches_table_request_acknowledged(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);
        $request = $this->createTableRequest($table, TableRequest::TYPE_REQUEST_BILL);

        Event::fake([TableRequestAcknowledged::class]);

        app(TransitionTableRequestStatusAction::class)->acknowledge($request, $owner);

        Event::assertDispatched(TableRequestAcknowledged::class, function (TableRequestAcknowledged $event) use ($request) {
            return $event->tableRequestId === $request->id
                && $event->status === TableRequest::STATUS_ACKNOWLEDGED
                && $event->broadcastAs() === 'table_request.acknowledged';
        });
    }

    public function test_completing_a_table_request_does_not_broadcast(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);
        $request = $this->createTableRequest($table, TableRequest::TYPE_REQUEST_BILL);
        $action = app(TransitionTableRequestStatusAction::class);
        $request = $action->acknowledge($request, $owner);

        Event::fake([TableRequestAcknowledged::class, TableRequestCreated::class]);

        $action->complete($request, $owner);

        Event::assertNotDispatched(TableRequestAcknowledged::class);
        Event::assertNotDispatched(TableRequestCreated::class);
    }

    public function test_calling_the_responsible_waiter_dispatches_waiter_call_created(): void
    {
        Event::fake([WaiterCallCreated::class]);

        [$organization, $owner, $restaurant] = $this->createTenant();
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);
        app(AssignWaiterAction::class)->execute($session, $waiter, $owner);

        $call = app(CallResponsibleWaiterAction::class)->execute($session, $owner);

        Event::assertDispatched(WaiterCallCreated::class, function (WaiterCallCreated $event) use ($restaurant, $table, $session, $call, $waiter) {
            return $event->restaurantId === $restaurant->id
                && $event->tableId === $table->id
                && $event->tableSessionId === $session->id
                && $event->waiterCallId === $call->id
                && $event->waiterUserId === $waiter->id
                && $event->status === WaiterCall::STATUS_PENDING
                && $event->broadcastAs() === 'waiter_call.created';
        });
    }

    public function test_acknowledging_a_waiter_call_dispatches_waiter_call_acknowledged(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);
        app(AssignWaiterAction::class)->execute($session, $waiter, $owner);
        $call = app(CallResponsibleWaiterAction::class)->execute($session, $owner);

        Event::fake([WaiterCallAcknowledged::class]);

        app(AcknowledgeWaiterCallAction::class)->execute($call, $waiter);

        Event::assertDispatched(WaiterCallAcknowledged::class, function (WaiterCallAcknowledged $event) use ($table, $call) {
            return $event->tableId === $table->id
                && $event->waiterCallId === $call->id
                && $event->status === WaiterCall::STATUS_ACKNOWLEDGED
                && $event->broadcastAs() === 'waiter_call.acknowledged';
        });
    }
}
