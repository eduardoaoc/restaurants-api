<?php

namespace Tests\Feature\Kitchen;

use App\Actions\Orders\RejectOrderAction;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithOrders;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class KitchenQueueTest extends TestCase
{
    use InteractsWithOrders, InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    public function test_content_type_is_json(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $this->actingAs($owner, 'web')
            ->getJson('/api/v1/kitchen/orders')
            ->assertHeader('Content-Type', 'application/json');
    }

    // --- Statuses shown ------------------------------------------------

    public function test_only_confirmed_accepted_preparing_ready_appear(): void
    {
        [$organization, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $kitchen = $this->createStaff($organization, $restaurant, 'kitchen', 'K-1');
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);

        $waitingApproval = $this->createCustomerOrder($table, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);
        $confirmed = $this->createWaiterOrder($table, $owner, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);
        $accepted = $this->advanceOrderTo(
            $this->createWaiterOrder($table, $owner, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]),
            Order::STATUS_ACCEPTED,
            $kitchen,
        );
        $preparing = $this->advanceOrderTo(
            $this->createWaiterOrder($table, $owner, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]),
            Order::STATUS_PREPARING,
            $kitchen,
        );
        $ready = $this->advanceOrderTo(
            $this->createWaiterOrder($table, $owner, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]),
            Order::STATUS_READY,
            $kitchen,
        );
        $served = $this->advanceOrderTo(
            $this->createWaiterOrder($table, $owner, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]),
            Order::STATUS_SERVED,
            $kitchen,
        );
        $cancelled = $this->createCustomerOrder($table, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);
        app(RejectOrderAction::class)->execute($cancelled, $owner);

        $response = $this->actingAs($owner, 'web')->getJson('/api/v1/kitchen/orders')->assertOk();
        $ids = collect($response->json('data.orders'))->pluck('id');

        $this->assertFalse($ids->contains($waitingApproval->id));
        $this->assertTrue($ids->contains($confirmed->id));
        $this->assertTrue($ids->contains($accepted->id));
        $this->assertTrue($ids->contains($preparing->id));
        $this->assertTrue($ids->contains($ready->id));
        $this->assertFalse($ids->contains($served->id));
        $this->assertFalse($ids->contains($cancelled->id));
        $this->assertCount(4, $ids);
    }

    public function test_waiter_created_order_appears_immediately(): void
    {
        [, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);
        $order = $this->createWaiterOrder($table, $owner, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);

        $response = $this->actingAs($owner, 'web')->getJson('/api/v1/kitchen/orders')->assertOk();

        $this->assertTrue(collect($response->json('data.orders'))->pluck('id')->contains($order->id));
    }

    public function test_customer_order_appears_only_after_approval(): void
    {
        [, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);
        $order = $this->createCustomerOrder($table, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);

        $before = $this->actingAs($owner, 'web')->getJson('/api/v1/kitchen/orders')->assertOk();
        $this->assertFalse(collect($before->json('data.orders'))->pluck('id')->contains($order->id));

        $this->actingAs($owner, 'web')->postJson("/api/v1/orders/{$order->id}/approve")->assertOk();

        $after = $this->actingAs($owner, 'web')->getJson('/api/v1/kitchen/orders')->assertOk();
        $this->assertTrue(collect($after->json('data.orders'))->pluck('id')->contains($order->id));
    }

    public function test_serving_an_order_removes_it_from_the_queue_but_it_stays_in_history(): void
    {
        [$organization, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $kitchen = $this->createStaff($organization, $restaurant, 'kitchen', 'K-1');
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);
        $order = $this->createWaiterOrder($table, $waiter, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);
        $this->advanceOrderTo($order, Order::STATUS_SERVED, $kitchen, $waiter);

        $queue = $this->actingAs($owner, 'web')->getJson('/api/v1/kitchen/orders')->assertOk();
        $this->assertFalse(collect($queue->json('data.orders'))->pluck('id')->contains($order->id));

        $this->actingAs($owner, 'web')
            ->getJson("/api/v1/orders/{$order->id}")
            ->assertOk()
            ->assertJson(['data' => ['order' => ['status' => 'served']]]);
    }

    // --- Status filter ---------------------------------------------------

    public function test_status_filter_narrows_the_queue(): void
    {
        [$organization, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $kitchen = $this->createStaff($organization, $restaurant, 'kitchen', 'K-1');
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);
        $confirmed = $this->createWaiterOrder($table, $owner, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);
        $this->advanceOrderTo(
            $this->createWaiterOrder($table, $owner, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]),
            Order::STATUS_ACCEPTED,
            $kitchen,
        );

        $response = $this->actingAs($owner, 'web')
            ->getJson('/api/v1/kitchen/orders?status=confirmed')
            ->assertOk();

        $this->assertSame([$confirmed->id], collect($response->json('data.orders'))->pluck('id')->all());
    }

    public function test_disallowed_status_filter_returns_422(): void
    {
        [, $owner] = $this->createTenant();

        $this->actingAs($owner, 'web')
            ->getJson('/api/v1/kitchen/orders?status=waiting_approval')
            ->assertStatus(422);

        $this->actingAs($owner, 'web')
            ->getJson('/api/v1/kitchen/orders?status=cancelled')
            ->assertStatus(422);

        $this->actingAs($owner, 'web')
            ->getJson('/api/v1/kitchen/orders?status=served')
            ->assertStatus(422);

        $this->actingAs($owner, 'web')
            ->getJson('/api/v1/kitchen/orders?status=not-a-real-status')
            ->assertStatus(422);
    }

    // --- Ordering -----------------------------------------------------

    public function test_orders_are_returned_oldest_first(): void
    {
        [, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);

        $first = $this->createWaiterOrder($table, $owner, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);
        $first->forceFill(['created_at' => now()->subMinutes(10)])->save();

        $second = $this->createWaiterOrder($table, $owner, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);
        $second->forceFill(['created_at' => now()->subMinutes(5)])->save();

        $third = $this->createWaiterOrder($table, $owner, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);

        $response = $this->actingAs($owner, 'web')->getJson('/api/v1/kitchen/orders')->assertOk();

        $this->assertSame(
            [$first->id, $second->id, $third->id],
            collect($response->json('data.orders'))->pluck('id')->all()
        );
    }

    public function test_ties_break_by_id_ascending(): void
    {
        [, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);

        $sameInstant = now()->subMinute();
        $first = $this->createWaiterOrder($table, $owner, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);
        $first->forceFill(['created_at' => $sameInstant])->save();
        $second = $this->createWaiterOrder($table, $owner, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);
        $second->forceFill(['created_at' => $sameInstant])->save();

        $response = $this->actingAs($owner, 'web')->getJson('/api/v1/kitchen/orders')->assertOk();

        $this->assertSame(
            [$first->id, $second->id],
            collect($response->json('data.orders'))->pluck('id')->all()
        );
    }

    // --- Snapshots ------------------------------------------------------

    public function test_kds_uses_snapshots_not_live_catalog_data(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $product = $this->createProduct($organization, null, [['locale' => 'es', 'name' => 'Nombre Original']]);
        $rp = $this->createRestaurantProduct($restaurant, $product, 10.0);
        $group = $this->createModifierGroup($rp, null, 0, 1, false, [['locale' => 'es', 'name' => 'Grupo Original']]);
        $option = $this->createModifierOption($group, null, 1.0, [['locale' => 'es', 'name' => 'Opcion Original']]);

        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);
        $order = $this->createWaiterOrder($table, $owner, [
            ['restaurant_product_id' => $rp->id, 'quantity' => 1, 'modifier_option_ids' => [$option->id]],
        ]);

        $product->translations()->update(['name' => 'Nombre Nuevo']);
        $group->translations()->update(['name' => 'Grupo Nuevo']);
        $option->translations()->update(['name' => 'Opcion Nueva']);

        $response = $this->actingAs($owner, 'web')->getJson('/api/v1/kitchen/orders')->assertOk();
        $item = collect($response->json('data.orders'))->firstWhere('id', $order->id)['items'][0];

        $this->assertSame('Nombre Original', $item['name']);
        $this->assertSame('Grupo Original', $item['modifiers'][0]['group_name']);
        $this->assertSame('Opcion Original', $item['modifiers'][0]['name']);
    }

    // --- No financial data -----------------------------------------------

    public function test_kds_never_returns_financial_fields(): void
    {
        [, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $group = $this->createModifierGroup($rp, null, 0, 1, false);
        $this->createModifierOption($group, null, 1.5);
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);
        $this->createWaiterOrder($table, $owner, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);

        $response = $this->actingAs($owner, 'web')->getJson('/api/v1/kitchen/orders')->assertOk();

        $json = json_encode($response->json());
        foreach (['unit_price', 'price_delta', 'subtotal', 'modifiers_total', '"total"'] as $field) {
            $this->assertStringNotContainsString($field, $json);
        }
    }

    // --- Resource contract ------------------------------------------

    public function test_kitchen_order_resource_contract(): void
    {
        [, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $table = $this->createTable($restaurant, 'Mesa 12', 12);
        $this->openSession($table, $owner);
        $this->createWaiterOrder($table, $owner, [
            ['restaurant_product_id' => $rp->id, 'quantity' => 1, 'note' => 'Sin cebolla'],
        ], ['note' => 'Order-level note']);

        $response = $this->actingAs($owner, 'web')->getJson('/api/v1/kitchen/orders')->assertOk();
        $orderJson = $response->json('data.orders.0');

        $this->assertEqualsCanonicalizing(
            ['id', 'status', 'origin', 'restaurant', 'table', 'order_note', 'created_at', 'elapsed_seconds', 'items'],
            array_keys($orderJson)
        );
        $this->assertSame('Order-level note', $orderJson['order_note']);
        $this->assertEqualsCanonicalizing(['id', 'name'], array_keys($orderJson['restaurant']));
        $this->assertEqualsCanonicalizing(['id', 'name', 'number'], array_keys($orderJson['table']));

        $itemJson = $orderJson['items'][0];
        $this->assertEqualsCanonicalizing(['id', 'name', 'quantity', 'note', 'modifiers'], array_keys($itemJson));
        $this->assertSame('Sin cebolla', $itemJson['note']);
    }

    public function test_elapsed_seconds_is_a_non_negative_integer(): void
    {
        [, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);
        $this->createWaiterOrder($table, $owner, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);

        $response = $this->actingAs($owner, 'web')->getJson('/api/v1/kitchen/orders')->assertOk();

        $elapsed = $response->json('data.orders.0.elapsed_seconds');
        $this->assertIsInt($elapsed);
        $this->assertGreaterThanOrEqual(0, $elapsed);
    }

    // --- Multiple orders per session -------------------------------------

    public function test_multiple_orders_in_the_same_session_appear_independently(): void
    {
        [$organization, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $kitchen = $this->createStaff($organization, $restaurant, 'kitchen', 'K-1');
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);

        $preparing = $this->createWaiterOrder($table, $owner, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);
        $this->advanceOrderTo($preparing, Order::STATUS_PREPARING, $kitchen);
        $confirmed = $this->createWaiterOrder($table, $owner, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);

        $response = $this->actingAs($owner, 'web')->getJson('/api/v1/kitchen/orders')->assertOk();
        $byId = collect($response->json('data.orders'))->keyBy('id');

        $this->assertSame('preparing', $byId[$preparing->id]['status']);
        $this->assertSame('confirmed', $byId[$confirmed->id]['status']);
    }

    // --- Limit ----------------------------------------------------------

    public function test_queue_is_capped_at_one_hundred_orders(): void
    {
        [, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);

        for ($i = 0; $i < 105; $i++) {
            $this->createWaiterOrder($table, $owner, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);
        }

        $response = $this->actingAs($owner, 'web')->getJson('/api/v1/kitchen/orders')->assertOk();

        $this->assertCount(100, $response->json('data.orders'));
    }
}
