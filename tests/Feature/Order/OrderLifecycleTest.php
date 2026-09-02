<?php

namespace Tests\Feature\Order;

use App\Actions\Orders\OrderCreationService;
use App\Actions\Orders\RejectOrderAction;
use App\Exceptions\Orders\InvalidOrderItemException;
use App\Exceptions\Orders\OrderCreationConflictException;
use App\Models\Order;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithOrders;
use Tests\Concerns\InteractsWithPayments;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class OrderLifecycleTest extends TestCase
{
    use InteractsWithOrders, InteractsWithPayments, InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    // --- Snapshots survive catalog changes -------------------------------

    public function test_order_keeps_its_snapshots_after_the_catalog_changes(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $product = $this->createProduct($organization, null, [['locale' => 'es', 'name' => 'Hamburguesa Original']]);
        $rp = $this->createRestaurantProduct($restaurant, $product, 12.90);
        $group = $this->createModifierGroup($rp, null, 0, 1, false, [['locale' => 'es', 'name' => 'Extras Original']]);
        $option = $this->createModifierOption($group, null, 1.50, [['locale' => 'es', 'name' => 'Bacon Original']]);

        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);
        $order = $this->createCustomerOrder($table, [
            ['restaurant_product_id' => $rp->id, 'quantity' => 1, 'modifier_option_ids' => [$option->id]],
        ]);

        // Now change everything the order snapshotted.
        $product->translations()->update(['name' => 'Hamburguesa Renovada']);
        $rp->update(['price' => 99.99]);
        $group->translations()->update(['name' => 'Extras Renovados']);
        $option->translations()->update(['name' => 'Bacon Renovado']);
        $option->update(['price_delta' => 5.00]);

        $response = $this->actingAs($owner, 'web')
            ->getJson("/api/v1/orders/{$order->id}")
            ->assertOk();

        $item = $response->json('data.order.items.0');
        $this->assertSame('Hamburguesa Original', $item['name']);
        $this->assertSame('12.90', $item['unit_price']);
        $this->assertSame('Extras Original', $item['modifiers'][0]['group_name']);
        $this->assertSame('Bacon Original', $item['modifiers'][0]['name']);
        $this->assertSame('1.50', $item['modifiers'][0]['price_delta']);
    }

    // --- Multiple orders per session --------------------------------------

    public function test_a_session_can_accumulate_multiple_orders(): void
    {
        [, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);

        $order1 = $this->createCustomerOrder($table, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);
        $order2 = $this->createCustomerOrder($table, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);
        $order3 = $this->createCustomerOrder($table, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);

        $this->assertSame($session->id, $order1->table_session_id);
        $this->assertSame($session->id, $order2->table_session_id);
        $this->assertSame($session->id, $order3->table_session_id);
        $this->assertSame(3, Order::query()->where('table_session_id', $session->id)->count());
    }

    // --- New session gets new orders --------------------------------------

    public function test_reopening_the_table_starts_a_fresh_session_for_new_orders(): void
    {
        [, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $table = $this->createTable($restaurant);

        $sessionA = $this->openSession($table, $owner);
        $orderA = $this->createCustomerOrder($table, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);
        // orderA stays waiting_approval, which would block close (it's an
        // "open" order) — reject it so it's out of the way, then close via
        // the helper's own separate served+paid order.
        app(RejectOrderAction::class)->execute($orderA, $owner);
        $this->closeSessionWithFullPayment($sessionA, $owner);

        $sessionB = $this->openSession($table, $owner);
        $orderB = $this->createCustomerOrder($table, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);

        $this->assertSame($sessionA->id, $orderA->table_session_id);
        $this->assertSame($sessionB->id, $orderB->table_session_id);
        $this->assertNotSame($sessionA->id, $sessionB->id);
    }

    // --- Transaction rollback --------------------------------------------

    public function test_a_rejected_item_leaves_no_partial_rows_behind(): void
    {
        [$organization, $owner, $restaurantA] = $this->createTenant();
        $restaurantB = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $productA = $this->createProduct($organization);
        $rpA = $this->createRestaurantProduct($restaurantA, $productA);
        $productB = $this->createProduct($organization);
        $rpB = $this->createRestaurantProduct($restaurantB, $productB);

        $table = $this->createTable($restaurantA);
        $this->openSession($table, $owner);

        try {
            $this->createCustomerOrder($table, [
                // Valid item first...
                ['restaurant_product_id' => $rpA->id, 'quantity' => 1],
                // ...then one that belongs to a different restaurant, which
                // must fail validation and roll back the whole order,
                // including the already-valid first item.
                ['restaurant_product_id' => $rpB->id, 'quantity' => 1],
            ]);
            $this->fail('Expected InvalidOrderItemException was not thrown.');
        } catch (InvalidOrderItemException) {
            // expected
        }

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_items', 0);
        $this->assertDatabaseCount('order_item_modifiers', 0);
    }

    // --- Concurrency: session closed between resolve and lock -----------

    public function test_order_creation_conflicts_when_session_closes_before_the_locked_recheck(): void
    {
        // OrderCreationService re-locks and rechecks the session itself
        // (lockForUpdate) rather than trusting the session passed in. This
        // calls it directly with a session id that is already closed —
        // exactly what it would observe if a concurrent CloseTableAction
        // had won the race — without needing a real parallel thread.
        [, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);
        $this->closeSessionWithFullPayment($session, $owner);

        $this->expectException(OrderCreationConflictException::class);

        app(OrderCreationService::class)->execute(
            table: $table->fresh('restaurant'),
            tableSessionId: $session->id,
            origin: Order::ORIGIN_CUSTOMER_QR,
            createdBy: null,
            locale: 'es',
            items: [['restaurant_product_id' => $rp->id, 'quantity' => 1]],
        );
    }

    // --- Index -------------------------------------------------------

    public function test_owner_lists_orders_across_all_organization_restaurants(): void
    {
        [$organization, $owner, $restaurantA, $rpA] = $this->createTenantWithRestaurantProduct();
        $restaurantB = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $productB = $this->createProduct($organization);
        $rpB = $this->createRestaurantProduct($restaurantB, $productB);

        $tableA = $this->createTable($restaurantA);
        $this->openSession($tableA, $owner);
        $this->createCustomerOrder($tableA, [['restaurant_product_id' => $rpA->id, 'quantity' => 1]]);

        $tableB = $this->createTable($restaurantB);
        $this->openSession($tableB, $owner);
        $this->createCustomerOrder($tableB, [['restaurant_product_id' => $rpB->id, 'quantity' => 1]]);

        $response = $this->actingAs($owner, 'web')->getJson('/api/v1/orders')->assertOk();

        $this->assertCount(2, $response->json('data.orders'));
    }

    public function test_waiter_only_sees_orders_of_their_own_restaurant(): void
    {
        [$organization, $owner, $restaurantA, $rpA] = $this->createTenantWithRestaurantProduct();
        $restaurantB = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $productB = $this->createProduct($organization);
        $rpB = $this->createRestaurantProduct($restaurantB, $productB);

        $waiterA = $this->createStaff($organization, $restaurantA, 'waiter', 'W-A');

        $tableA = $this->createTable($restaurantA);
        $this->openSession($tableA, $owner);
        $orderA = $this->createCustomerOrder($tableA, [['restaurant_product_id' => $rpA->id, 'quantity' => 1]]);

        $tableB = $this->createTable($restaurantB);
        $this->openSession($tableB, $owner);
        $this->createCustomerOrder($tableB, [['restaurant_product_id' => $rpB->id, 'quantity' => 1]]);

        $response = $this->actingAs($waiterA, 'web')->getJson('/api/v1/orders')->assertOk();

        $orders = collect($response->json('data.orders'));
        $this->assertCount(1, $orders);
        $this->assertSame($orderA->id, $orders->first()['id']);
    }

    public function test_other_tenant_orders_never_appear_in_the_list(): void
    {
        [$organization, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);
        $this->createCustomerOrder($table, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);

        [, $otherOwner] = $this->createTenant();

        $response = $this->actingAs($otherOwner, 'web')->getJson('/api/v1/orders')->assertOk();

        $this->assertCount(0, $response->json('data.orders'));
    }

    public function test_filter_by_status(): void
    {
        [, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);
        $waiting = $this->createCustomerOrder($table, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);
        $this->createWaiterOrder($table, $owner, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);

        $response = $this->actingAs($owner, 'web')
            ->getJson('/api/v1/orders?status=waiting_approval')
            ->assertOk();

        $orders = collect($response->json('data.orders'));
        $this->assertCount(1, $orders);
        $this->assertSame($waiting->id, $orders->first()['id']);
    }

    public function test_index_without_any_operational_permission_is_forbidden(): void
    {
        [$organization] = $this->createTenant();

        // A user attached to the organization but holding no role/permission
        // at all — organization membership alone must not grant visibility.
        $bystander = User::factory()->create();
        $organization->users()->attach($bystander->id);

        $this->actingAs($bystander, 'web')
            ->getJson('/api/v1/orders')
            ->assertStatus(403);
    }

    public function test_cashier_with_close_bill_permission_can_view_orders(): void
    {
        [$organization, , $restaurant] = $this->createTenant();
        $cashier = $this->createStaff($organization, $restaurant, 'cashier', 'C-1');

        $this->actingAs($cashier, 'web')->getJson('/api/v1/orders')->assertOk();
    }

    // --- Show ----------------------------------------------------------

    public function test_show_returns_the_order_for_its_own_restaurant(): void
    {
        [, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);
        $order = $this->createCustomerOrder($table, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);

        $this->actingAs($owner, 'web')
            ->getJson("/api/v1/orders/{$order->id}")
            ->assertOk()
            ->assertJson(['data' => ['order' => ['id' => $order->id]]]);
    }

    public function test_show_cross_tenant_returns_404(): void
    {
        [, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);
        $order = $this->createCustomerOrder($table, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);

        [, $otherOwner] = $this->createTenant();

        $this->actingAs($otherOwner, 'web')
            ->getJson("/api/v1/orders/{$order->id}")
            ->assertStatus(404);
    }

    public function test_show_cross_restaurant_scope_returns_404(): void
    {
        [$organization, $owner, $restaurantA, $rpA] = $this->createTenantWithRestaurantProduct();
        $restaurantB = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $waiterB = $this->createStaff($organization, $restaurantB, 'waiter', 'W-B');

        $tableA = $this->createTable($restaurantA);
        $this->openSession($tableA, $owner);
        $order = $this->createCustomerOrder($tableA, [['restaurant_product_id' => $rpA->id, 'quantity' => 1]]);

        $this->actingAs($waiterB, 'web')
            ->getJson("/api/v1/orders/{$order->id}")
            ->assertStatus(404);
    }
}
