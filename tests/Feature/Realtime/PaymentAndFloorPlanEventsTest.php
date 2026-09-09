<?php

namespace Tests\Feature\Realtime;

use App\Actions\FloorPlan\UpdateFloorPlanLayoutAction;
use App\Events\Realtime\FloorPlanUpdated;
use App\Events\Realtime\PaymentRecorded;
use App\Models\PaymentRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\InteractsWithOrders;
use Tests\Concerns\InteractsWithPayments;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bloco 7 — realtime events for RecordPaymentAction/
 * UpdateFloorPlanLayoutAction.
 */
class PaymentAndFloorPlanEventsTest extends TestCase
{
    use InteractsWithOrders, InteractsWithPayments, InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
    }

    public function test_recording_a_payment_dispatches_payment_recorded(): void
    {
        Event::fake([PaymentRecorded::class]);

        [$organization, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);
        $restaurantProduct = $this->createRestaurantProduct($restaurant, $this->createProduct($organization), 25.0);
        $this->createWaiterOrder($table, $owner, [
            ['restaurant_product_id' => $restaurantProduct->id, 'quantity' => 1],
        ]);

        $result = $this->recordPayment($session, $owner, '25.00');

        Event::assertDispatched(PaymentRecorded::class, function (PaymentRecorded $event) use ($restaurant, $table, $session, $result) {
            return $event->restaurantId === $restaurant->id
                && $event->tableId === $table->id
                && $event->tableSessionId === $session->id
                && $event->paymentId === $result['payment']->id
                && $event->amount === '25.00'
                && $event->paymentMethod === PaymentRecord::METHOD_CASH
                && $event->broadcastAs() === 'payment.recorded';
        });
    }

    public function test_an_idempotent_payment_replay_does_not_broadcast_again(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);
        $restaurantProduct = $this->createRestaurantProduct($restaurant, $this->createProduct($organization), 25.0);
        $this->createWaiterOrder($table, $owner, [
            ['restaurant_product_id' => $restaurantProduct->id, 'quantity' => 1],
        ]);
        $this->recordPayment($session, $owner, '25.00', extra: ['idempotency_key' => 'idem-1']);

        Event::fake([PaymentRecorded::class]);

        $replay = $this->recordPayment($session, $owner, '25.00', extra: ['idempotency_key' => 'idem-1']);

        $this->assertTrue($replay['replayed']);
        Event::assertNotDispatched(PaymentRecorded::class);
    }

    public function test_updating_the_floor_plan_layout_dispatches_one_floor_plan_updated_event(): void
    {
        Event::fake([FloorPlanUpdated::class]);

        [, $owner, $restaurant] = $this->createTenant();
        $tableA = $this->createTable($restaurant);
        $tableB = $this->createTable($restaurant);

        app(UpdateFloorPlanLayoutAction::class)->execute($restaurant, [
            ['id' => $tableA->id, 'layout_x' => 0.10, 'layout_y' => 0.20],
            ['id' => $tableB->id, 'layout_x' => 0.30, 'layout_y' => 0.40],
        ], $owner);

        Event::assertDispatchedTimes(FloorPlanUpdated::class, 1);
        Event::assertDispatched(FloorPlanUpdated::class, function (FloorPlanUpdated $event) use ($restaurant, $tableA, $tableB) {
            return $event->restaurantId === $restaurant->id
                && $event->tablesUpdatedCount === 2
                && $event->tableIds === [$tableA->id, $tableB->id]
                && $event->broadcastAs() === 'floor_plan.updated';
        });
    }
}
