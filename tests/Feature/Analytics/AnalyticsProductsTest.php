<?php

namespace Tests\Feature\Analytics;

use App\Actions\Orders\RejectOrderAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithOrders;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bloco 6 — top products, computed exclusively from OrderItem snapshots
 * (never joined to the live Product): renaming/deleting the Product must
 * never rewrite what analytics already recorded as sold.
 */
class AnalyticsProductsTest extends TestCase
{
    use InteractsWithOrders, InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    public function test_quantity_and_revenue_are_aggregated_from_the_snapshot(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);
        $product = $this->createProduct($organization, null, [['locale' => 'en', 'name' => 'Paella Valenciana']]);
        $restaurantProduct = $this->createRestaurantProduct($restaurant, $product, 18.0);

        $this->createWaiterOrder($table, $owner, [
            ['restaurant_product_id' => $restaurantProduct->id, 'quantity' => 3],
        ]);

        $response = $this->actingAs($owner, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/analytics?from=2026-01-01&to=2026-12-31")
            ->assertOk();

        $top = collect($response->json('data.products.top_by_quantity'))->first();
        $this->assertSame('Paella Valenciana', $top['name']);
        $this->assertSame(3, $top['quantity']);
        $this->assertSame('54.00', $top['revenue']);
    }

    public function test_renaming_the_product_afterward_does_not_rewrite_past_analytics(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);
        $product = $this->createProduct($organization, null, [['locale' => 'en', 'name' => 'Original Name']]);
        $restaurantProduct = $this->createRestaurantProduct($restaurant, $product, 10.0);
        $this->createWaiterOrder($table, $owner, [
            ['restaurant_product_id' => $restaurantProduct->id, 'quantity' => 1],
        ]);

        $product->translations()->update(['name' => 'Renamed Later']);

        $response = $this->actingAs($owner, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/analytics?from=2026-01-01&to=2026-12-31")
            ->assertOk();

        $names = collect($response->json('data.products.top_by_quantity'))->pluck('name')->all();
        $this->assertContains('Original Name', $names);
        $this->assertNotContains('Renamed Later', $names);
    }

    /**
     * A Product that has ever been ordered cannot actually be deleted
     * today — restaurant_products.product_id cascades, but order_items
     * blocks deleting the RestaurantProduct via restrictOnDelete on
     * restaurant_product_id, transitively blocking the Product delete too
     * (confirmed directly against Postgres — see the Bloco 6 report's
     * Findings). order_items.product_id itself IS nullOnDelete and exists
     * precisely so a gone Product can't break history; this test exercises
     * that state directly (a real future path — e.g. a hard-delete tool
     * bypassing the ORM, or the constraint relaxing later) rather than
     * asserting a Product::delete() call this schema does not allow yet.
     */
    public function test_order_item_with_no_live_product_reference_is_still_representable(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);
        $product = $this->createProduct($organization, null, [['locale' => 'en', 'name' => 'Soon Gone']]);
        $restaurantProduct = $this->createRestaurantProduct($restaurant, $product, 12.0);
        $order = $this->createWaiterOrder($table, $owner, [
            ['restaurant_product_id' => $restaurantProduct->id, 'quantity' => 2],
        ]);

        $order->items()->update(['product_id' => null]);

        $response = $this->actingAs($owner, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/analytics?from=2026-01-01&to=2026-12-31")
            ->assertOk();

        $top = collect($response->json('data.products.top_by_quantity'))->firstWhere('name', 'Soon Gone');
        $this->assertNotNull($top);
        $this->assertNull($top['id']);
        $this->assertSame(2, $top['quantity']);
    }

    public function test_cancelled_order_items_are_excluded_from_products(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $this->requireOrderApproval($restaurant);
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);
        $product = $this->createProduct($organization, null, [['locale' => 'en', 'name' => 'Rejected Item']]);
        $restaurantProduct = $this->createRestaurantProduct($restaurant, $product, 20.0);

        $order = $this->createCustomerOrder($table, [
            ['restaurant_product_id' => $restaurantProduct->id, 'quantity' => 1],
        ]);
        app(RejectOrderAction::class)->execute($order, $owner);

        $response = $this->actingAs($owner, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/analytics?from=2026-01-01&to=2026-12-31")
            ->assertOk();

        $names = collect($response->json('data.products.top_by_quantity'))->pluck('name')->all();
        $this->assertNotContains('Rejected Item', $names);
    }

    public function test_top_by_revenue_orders_differently_from_top_by_quantity(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);

        // Cheap product, sold a lot.
        $cheap = $this->createProduct($organization, null, [['locale' => 'en', 'name' => 'Cheap High Volume']]);
        $cheapRp = $this->createRestaurantProduct($restaurant, $cheap, 1.0);
        $this->createWaiterOrder($table, $owner, [
            ['restaurant_product_id' => $cheapRp->id, 'quantity' => 10],
        ]);

        // Expensive product, sold once.
        $expensive = $this->createProduct($organization, null, [['locale' => 'en', 'name' => 'Expensive Low Volume']]);
        $expensiveRp = $this->createRestaurantProduct($restaurant, $expensive, 200.0);
        $this->createWaiterOrder($table, $owner, [
            ['restaurant_product_id' => $expensiveRp->id, 'quantity' => 1],
        ]);

        $response = $this->actingAs($owner, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/analytics?from=2026-01-01&to=2026-12-31")
            ->assertOk();

        $this->assertSame('Cheap High Volume', $response->json('data.products.top_by_quantity.0.name'));
        $this->assertSame('Expensive Low Volume', $response->json('data.products.top_by_revenue.0.name'));
    }
}
