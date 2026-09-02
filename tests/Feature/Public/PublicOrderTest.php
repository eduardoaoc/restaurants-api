<?php

namespace Tests\Feature\Public;

use App\Models\Order;
use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithOrders;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class PublicOrderTest extends TestCase
{
    use InteractsWithOrders, InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
    }

    // --- Basic creation --------------------------------------------------

    public function test_creates_an_order_without_authentication(): void
    {
        [, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);

        $response = $this->postJson("/api/v1/public/tables/{$table->public_token}/orders", [
            'customer_name' => 'Carlos',
            'items' => [
                ['restaurant_product_id' => $rp->id, 'quantity' => 1],
            ],
        ])->assertStatus(201);

        $response->assertJson(['data' => ['status' => 'waiting_approval']]);
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_status_is_waiting_approval_and_origin_is_customer_qr(): void
    {
        [, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);

        $this->postJson("/api/v1/public/tables/{$table->public_token}/orders", [
            'items' => [['restaurant_product_id' => $rp->id, 'quantity' => 1]],
        ])->assertStatus(201);

        $order = Order::query()->firstOrFail();
        $this->assertSame(Order::ORIGIN_CUSTOMER_QR, $order->origin);
        $this->assertSame(Order::STATUS_WAITING_APPROVAL, $order->status);
        $this->assertNull($order->created_by_user_id);
    }

    public function test_content_type_is_json(): void
    {
        [, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);

        $this->postJson("/api/v1/public/tables/{$table->public_token}/orders", [
            'items' => [['restaurant_product_id' => $rp->id, 'quantity' => 1]],
        ])->assertHeader('Content-Type', 'application/json');
    }

    // --- Session requirement ---------------------------------------------

    public function test_requires_an_active_table_session(): void
    {
        [, , $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $table = $this->createTable($restaurant);

        $response = $this->postJson("/api/v1/public/tables/{$table->public_token}/orders", [
            'items' => [['restaurant_product_id' => $rp->id, 'quantity' => 1]],
        ])->assertStatus(409);

        $response->assertJson(['error' => ['code' => 'TABLE_SESSION_NOT_ACTIVE', 'message' => 'The table session is not active.']]);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_closed_session_also_rejects_order(): void
    {
        [, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);
        $session->update(['status' => 'closed', 'closed_at' => now()]);

        $this->postJson("/api/v1/public/tables/{$table->public_token}/orders", [
            'items' => [['restaurant_product_id' => $rp->id, 'quantity' => 1]],
        ])->assertStatus(409)
            ->assertJson(['error' => ['code' => 'TABLE_SESSION_NOT_ACTIVE']]);
    }

    // --- Table/restaurant resolution --------------------------------------

    public function test_inactive_table_returns_public_table_not_found(): void
    {
        [, , $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $table = $this->createTable($restaurant);
        $table->update(['status' => 'inactive']);

        $this->postJson("/api/v1/public/tables/{$table->public_token}/orders", [
            'items' => [['restaurant_product_id' => $rp->id, 'quantity' => 1]],
        ])->assertStatus(404)
            ->assertJson(['error' => ['code' => 'PUBLIC_TABLE_NOT_FOUND']]);
    }

    public function test_inactive_restaurant_returns_public_table_not_found(): void
    {
        [, , $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $table = $this->createTable($restaurant);
        $restaurant->update(['status' => 'inactive']);

        $this->postJson("/api/v1/public/tables/{$table->public_token}/orders", [
            'items' => [['restaurant_product_id' => $rp->id, 'quantity' => 1]],
        ])->assertStatus(404)
            ->assertJson(['error' => ['code' => 'PUBLIC_TABLE_NOT_FOUND']]);
    }

    // --- Item validation --------------------------------------------------

    public function test_restaurant_product_from_another_restaurant_is_rejected(): void
    {
        [$organization, $owner, $restaurantA] = $this->createTenant();
        $restaurantB = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $product = $this->createProduct($organization);
        $rpB = $this->createRestaurantProduct($restaurantB, $product);

        $tableA = $this->createTable($restaurantA);
        $this->openSession($tableA, $owner);

        $response = $this->postJson("/api/v1/public/tables/{$tableA->public_token}/orders", [
            'items' => [['restaurant_product_id' => $rpB->id, 'quantity' => 1]],
        ])->assertStatus(422);

        $response->assertJson(['error' => ['code' => 'INVALID_ORDER_ITEM']]);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_inactive_product_is_rejected(): void
    {
        [, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $rp->product->update(['status' => 'inactive']);
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);

        $this->postJson("/api/v1/public/tables/{$table->public_token}/orders", [
            'items' => [['restaurant_product_id' => $rp->id, 'quantity' => 1]],
        ])->assertStatus(422)
            ->assertJson(['error' => ['code' => 'INVALID_ORDER_ITEM']]);
    }

    public function test_unavailable_restaurant_product_is_rejected(): void
    {
        [, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $rp->update(['available' => false]);
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);

        $this->postJson("/api/v1/public/tables/{$table->public_token}/orders", [
            'items' => [['restaurant_product_id' => $rp->id, 'quantity' => 1]],
        ])->assertStatus(422)
            ->assertJson(['error' => ['code' => 'INVALID_ORDER_ITEM']]);
    }

    public function test_empty_items_is_rejected(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);

        $this->postJson("/api/v1/public/tables/{$table->public_token}/orders", [
            'items' => [],
        ])->assertStatus(422);
    }

    public function test_invalid_quantity_is_rejected(): void
    {
        [, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);

        $this->postJson("/api/v1/public/tables/{$table->public_token}/orders", [
            'items' => [['restaurant_product_id' => $rp->id, 'quantity' => 0]],
        ])->assertStatus(422);

        $this->postJson("/api/v1/public/tables/{$table->public_token}/orders", [
            'items' => [['restaurant_product_id' => $rp->id, 'quantity' => 51]],
        ])->assertStatus(422);
    }

    public function test_frontend_supplied_price_and_context_fields_are_ignored(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $product = $this->createProduct($organization);
        $rp = $this->createRestaurantProduct($restaurant, $product, 12.90);
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);

        $response = $this->postJson("/api/v1/public/tables/{$table->public_token}/orders", [
            'restaurant_id' => 999999,
            'table_id' => 999999,
            'status' => 'confirmed',
            'origin' => 'waiter',
            'total' => '0.01',
            'items' => [['restaurant_product_id' => $rp->id, 'quantity' => 1, 'price' => '0.01']],
        ])->assertStatus(201);

        $order = Order::query()->firstOrFail();
        $this->assertSame($restaurant->id, $order->restaurant_id);
        $this->assertSame($table->id, $order->table_id);
        $this->assertSame(Order::STATUS_WAITING_APPROVAL, $order->status);
        $this->assertSame(Order::ORIGIN_CUSTOMER_QR, $order->origin);
        $this->assertSame('12.90', (string) $order->total);
    }

    // --- Errors are neutral / stable --------------------------------------

    public function test_error_responses_are_json_with_stable_codes(): void
    {
        $this->postJson('/api/v1/public/tables/unknown-token/orders', ['items' => [['restaurant_product_id' => 1, 'quantity' => 1]]])
            ->assertStatus(404)
            ->assertHeader('Content-Type', 'application/json')
            ->assertJson(['error' => ['code' => 'PUBLIC_TABLE_NOT_FOUND', 'message' => 'Table not found.']]);
    }
}
