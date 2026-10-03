<?php

namespace Tests\Feature\Realtime;

use App\Actions\Orders\ApproveOrderAction;
use App\Actions\Orders\RejectOrderAction;
use App\Actions\Orders\TransitionOrderStatusAction;
use App\Events\Realtime\OrderCreated;
use App\Events\Realtime\OrderStatusChanged;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\InteractsWithOrders;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bloco 7 — realtime events for order creation and every status
 * transition (OrderCreationService, ApproveOrderAction, RejectOrderAction,
 * TransitionOrderStatusAction) — one order.status_changed per Action call,
 * never duplicated (item 64).
 */
class OrderEventsTest extends TestCase
{
    use InteractsWithOrders, InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
    }

    public function test_creating_a_waiter_order_dispatches_order_created(): void
    {
        Event::fake([OrderCreated::class]);

        [$organization, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);
        $restaurantProduct = $this->createRestaurantProduct($restaurant, $this->createProduct($organization), 10.0);

        $order = $this->createWaiterOrder($table, $owner, [
            ['restaurant_product_id' => $restaurantProduct->id, 'quantity' => 1],
        ]);

        Event::assertDispatched(OrderCreated::class, function (OrderCreated $event) use ($restaurant, $table, $session, $order) {
            return $event->restaurantId === $restaurant->id
                && $event->tableId === $table->id
                && $event->tableSessionId === $session->id
                && $event->orderId === $order->id
                && $event->origin === Order::ORIGIN_WAITER
                && $event->status === Order::STATUS_CONFIRMED
                && $event->broadcastAs() === 'order.created';
        });
    }

    public function test_creating_a_customer_order_dispatches_order_created_with_customer_origin(): void
    {
        Event::fake([OrderCreated::class]);

        [$organization, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);
        $restaurantProduct = $this->createRestaurantProduct($restaurant, $this->createProduct($organization), 10.0);

        $this->createCustomerOrder($table, [
            ['restaurant_product_id' => $restaurantProduct->id, 'quantity' => 1],
        ]);

        Event::assertDispatched(OrderCreated::class, fn (OrderCreated $event) => $event->origin === Order::ORIGIN_CUSTOMER_QR);
    }

    public function test_approving_a_customer_order_dispatches_order_status_changed(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $this->requireOrderApproval($restaurant);
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);
        $restaurantProduct = $this->createRestaurantProduct($restaurant, $this->createProduct($organization), 10.0);
        $order = $this->createCustomerOrder($table, [
            ['restaurant_product_id' => $restaurantProduct->id, 'quantity' => 1],
        ]);

        Event::fake([OrderStatusChanged::class]);

        app(ApproveOrderAction::class)->execute($order, $owner);

        Event::assertDispatched(OrderStatusChanged::class, function (OrderStatusChanged $event) use ($order) {
            return $event->orderId === $order->id
                && $event->previousStatus === Order::STATUS_WAITING_APPROVAL
                && $event->status === Order::STATUS_CONFIRMED
                && $event->broadcastAs() === 'order.status_changed';
        });
    }

    public function test_rejecting_a_customer_order_dispatches_order_status_changed(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $this->requireOrderApproval($restaurant);
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);
        $restaurantProduct = $this->createRestaurantProduct($restaurant, $this->createProduct($organization), 10.0);
        $order = $this->createCustomerOrder($table, [
            ['restaurant_product_id' => $restaurantProduct->id, 'quantity' => 1],
        ]);

        Event::fake([OrderStatusChanged::class]);

        app(RejectOrderAction::class)->execute($order, $owner);

        Event::assertDispatched(OrderStatusChanged::class, function (OrderStatusChanged $event) use ($order) {
            return $event->orderId === $order->id
                && $event->previousStatus === Order::STATUS_WAITING_APPROVAL
                && $event->status === Order::STATUS_CANCELLED
                && $event->broadcastAs() === 'order.status_changed';
        });
    }

    public function test_kitchen_lifecycle_dispatches_one_status_changed_event_per_transition(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);
        $restaurantProduct = $this->createRestaurantProduct($restaurant, $this->createProduct($organization), 10.0);
        $order = $this->createWaiterOrder($table, $owner, [
            ['restaurant_product_id' => $restaurantProduct->id, 'quantity' => 1],
        ]);

        Event::fake([OrderStatusChanged::class]);

        $action = app(TransitionOrderStatusAction::class);
        $order = $action->accept($order, $owner);
        $order = $action->startPreparing($order, $owner);
        $order = $action->markReady($order, $owner);
        $action->serve($order, $owner);

        Event::assertDispatchedTimes(OrderStatusChanged::class, 4);
        Event::assertDispatched(OrderStatusChanged::class, fn (OrderStatusChanged $e) => $e->previousStatus === Order::STATUS_CONFIRMED && $e->status === Order::STATUS_ACCEPTED);
        Event::assertDispatched(OrderStatusChanged::class, fn (OrderStatusChanged $e) => $e->previousStatus === Order::STATUS_ACCEPTED && $e->status === Order::STATUS_PREPARING);
        Event::assertDispatched(OrderStatusChanged::class, fn (OrderStatusChanged $e) => $e->previousStatus === Order::STATUS_PREPARING && $e->status === Order::STATUS_READY);
        Event::assertDispatched(OrderStatusChanged::class, fn (OrderStatusChanged $e) => $e->previousStatus === Order::STATUS_READY && $e->status === Order::STATUS_SERVED);
    }
}
