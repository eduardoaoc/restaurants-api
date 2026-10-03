<?php

namespace Tests\Feature\Kitchen;

use App\Actions\Orders\TransitionOrderStatusAction;
use App\Events\Realtime\OrderStatusChanged;
use App\Exceptions\Orders\OrderStateConflictException;
use App\Models\Order;
use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\InteractsWithOrders;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class KitchenOperationalAccessTest extends TestCase
{
    use InteractsWithOrders, InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    public function test_kitchen_receives_only_operational_data_on_every_order_surface(): void
    {
        [$organization, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $kitchen = $this->createStaff($organization, $restaurant, 'kitchen', 'K-1');
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);
        $order = $this->createWaiterOrder($table, $owner, [['restaurant_product_id' => $rp->id, 'quantity' => 2, 'note' => 'Sin cebolla']]);
        $this->actingAs($kitchen, 'web');

        $responses = [
            $this->getJson('/api/v1/kitchen/orders')->assertOk()->json('data.orders.0'),
            $this->getJson('/api/v1/orders')->assertOk()->json('data.orders.0'),
            $this->getJson("/api/v1/orders/{$order->id}")->assertOk()->json('data.order'),
        ];
        foreach (['accept', 'preparing', 'ready'] as $action) {
            $responses[] = $this->postJson("/api/v1/orders/{$order->id}/{$action}")->assertOk()->json('data.order');
        }
        foreach ($responses as $data) {
            $this->assertSame('#'.$order->id, $data['order_number']);
            $this->assertSame('Sin cebolla', $data['items'][0]['note']);
            $this->assertSame(2, $data['items'][0]['quantity']);
            foreach (['total', 'subtotal', 'modifiers_total', 'customer_name', 'created_by_user_id', 'approved_by_user_id', 'accepted_by_user_id', 'ready_by_user_id'] as $field) {
                $this->assertArrayNotHasKey($field, $data);
            }
            $this->assertArrayNotHasKey('unit_price', $data['items'][0]);
        }
    }

    public function test_kitchen_cannot_administer_or_perform_waiter_and_billing_actions(): void
    {
        [$organization, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $kitchen = $this->createStaff($organization, $restaurant, 'kitchen', 'K-1');
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);
        $order = $this->createWaiterOrder($table, $owner, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);
        $this->advanceOrderTo($order, Order::STATUS_READY, $owner);
        $this->actingAs($kitchen, 'web');

        foreach (["/restaurants/{$restaurant->id}/menu", '/staff', "/restaurants/{$restaurant->id}/settings"] as $path) {
            $this->getJson('/api/v1'.$path)->assertForbidden();
        }
        $this->patchJson('/api/v1/organization', ['name' => 'Unauthorized'])->assertForbidden();
        $this->patchJson("/api/v1/restaurants/{$restaurant->id}", ['name' => 'Unauthorized'])->assertForbidden();
        $this->postJson('/api/v1/restaurants', ['name' => 'Unauthorized', 'slug' => 'unauthorized'])->assertForbidden();
        $this->patchJson("/api/v1/restaurants/{$restaurant->id}/settings", ['customer_order_requires_approval' => false])->assertForbidden();
        $this->postJson("/api/v1/table-sessions/{$session->id}/payments", ['amount' => '1.00', 'method' => 'cash'])->assertForbidden();
        $this->postJson("/api/v1/tables/{$table->id}/close")->assertForbidden();
        $this->postJson("/api/v1/orders/{$order->id}/served")->assertForbidden();
        $this->assertSame(Order::STATUS_READY, $order->fresh()->status);
    }

    public function test_kitchen_cannot_read_print_or_transition_a_sibling_restaurant_order(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $kitchen = $this->createStaff($organization, $restaurant, 'kitchen', 'K-1');
        $sibling = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $rp = $this->createRestaurantProduct($sibling, $this->createProduct($organization));
        $table = $this->createTable($sibling);
        $this->openSession($table, $owner);
        $order = $this->createWaiterOrder($table, $owner, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);
        $this->actingAs($kitchen, 'web');
        foreach (["/orders/{$order->id}", "/orders/{$order->id}/kitchen-ticket", "/kitchen/orders?restaurant_id={$sibling->id}"] as $path) {
            $this->getJson('/api/v1'.$path)->assertNotFound();
        }
        foreach (['accept', 'preparing', 'ready', 'kitchen-ticket/print'] as $action) {
            $this->postJson("/api/v1/orders/{$order->id}/{$action}")->assertNotFound();
        }
        $this->assertDatabaseCount('print_records', 0);
        $this->assertSame(Order::STATUS_CONFIRMED, $order->fresh()->status);
    }

    public function test_a_second_kitchen_actor_with_a_stale_order_cannot_repeat_a_transition(): void
    {
        [$organization, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $first = $this->createStaff($organization, $restaurant, 'kitchen', 'K-1');
        $second = $this->createStaff($organization, $restaurant, 'kitchen', 'K-2');
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);
        $order = $this->createWaiterOrder($table, $owner, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);
        $stale = $order->fresh();
        Event::fake([OrderStatusChanged::class]);
        $action = app(TransitionOrderStatusAction::class);
        $action->accept($order, $first);
        try {
            $action->accept($stale, $second);
            $this->fail('A stale transition must conflict.');
        } catch (OrderStateConflictException) {
            $this->assertSame(Order::STATUS_ACCEPTED, $order->fresh()->status);
            $this->assertSame($first->id, $order->fresh()->accepted_by_user_id);
        }
        Event::assertDispatchedTimes(OrderStatusChanged::class, 1);
    }
}
