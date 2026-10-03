<?php

namespace Tests\Feature\Analytics;

use App\Actions\Orders\RejectOrderAction;
use App\Actions\Orders\TransitionOrderStatusAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\InteractsWithOrders;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bloco 6 — kitchen metrics computed ONLY from reliably-guaranteed
 * timestamps (preparing_at -> ready_at, both stamped atomically by
 * TransitionOrderStatusAction under a strict sequential state machine).
 * average_preparation_time_seconds must never be derived from a borrowed
 * timestamp like updated_at, and must be null (not 0, not fabricated)
 * whenever no order in the period reached ready.
 */
class AnalyticsKitchenTest extends TestCase
{
    use InteractsWithOrders, InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_average_preparation_time_is_computed_from_preparing_to_ready(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $restaurant->settings()->update(['timezone' => 'UTC']);
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);
        $restaurantProduct = $this->createRestaurantProduct($restaurant, $this->createProduct($organization), 10.0);

        $action = app(TransitionOrderStatusAction::class);

        Carbon::setTestNow(Carbon::parse('2026-09-01 12:00:00', 'UTC'));
        $orderA = $this->createWaiterOrder($table, $owner, [
            ['restaurant_product_id' => $restaurantProduct->id, 'quantity' => 1],
        ]);
        $orderA = $action->accept($orderA, $owner);
        Carbon::setTestNow(Carbon::parse('2026-09-01 12:00:10', 'UTC'));
        $orderA = $action->startPreparing($orderA, $owner);
        Carbon::setTestNow(Carbon::parse('2026-09-01 12:01:10', 'UTC'));
        // preparing -> ready took 60s for order A.
        $action->markReady($orderA, $owner);

        $orderB = $this->createWaiterOrder($table, $owner, [
            ['restaurant_product_id' => $restaurantProduct->id, 'quantity' => 1],
        ]);
        $orderB = $action->accept($orderB, $owner);
        Carbon::setTestNow(Carbon::parse('2026-09-01 13:00:00', 'UTC'));
        $orderB = $action->startPreparing($orderB, $owner);
        Carbon::setTestNow(Carbon::parse('2026-09-01 13:02:00', 'UTC'));
        // preparing -> ready took 120s for order B.
        $action->markReady($orderB, $owner);
        Carbon::setTestNow();

        $response = $this->actingAs($owner, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/analytics?from=2026-09-01&to=2026-09-01")
            ->assertOk();

        // average of 60s and 120s.
        $this->assertSame(90, $response->json('data.kitchen.average_preparation_time_seconds'));
        $this->assertSame(2, $response->json('data.kitchen.orders_ready'));
    }

    public function test_average_preparation_time_is_null_when_no_order_reached_ready(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);
        $restaurantProduct = $this->createRestaurantProduct($restaurant, $this->createProduct($organization), 10.0);

        $order = $this->createWaiterOrder($table, $owner, [
            ['restaurant_product_id' => $restaurantProduct->id, 'quantity' => 1],
        ]);
        $this->advanceOrderTo($order, 'preparing', $owner);
        // Never marked ready.

        $response = $this->actingAs($owner, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/analytics?from=2026-01-01&to=2026-12-31")
            ->assertOk();

        $this->assertNull($response->json('data.kitchen.average_preparation_time_seconds'));
        $this->assertSame(0, $response->json('data.kitchen.orders_ready'));
        $this->assertSame(1, $response->json('data.kitchen.orders_created'));
    }

    public function test_cancelled_orders_are_counted_separately_and_never_affect_preparation_average(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $this->requireOrderApproval($restaurant);
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);
        $restaurantProduct = $this->createRestaurantProduct($restaurant, $this->createProduct($organization), 10.0);

        $order = $this->createCustomerOrder($table, [
            ['restaurant_product_id' => $restaurantProduct->id, 'quantity' => 1],
        ]);
        app(RejectOrderAction::class)->execute($order, $owner);

        $response = $this->actingAs($owner, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/analytics?from=2026-01-01&to=2026-12-31")
            ->assertOk();

        $this->assertSame(1, $response->json('data.kitchen.orders_cancelled'));
        $this->assertSame(0, $response->json('data.kitchen.orders_ready'));
        $this->assertNull($response->json('data.kitchen.average_preparation_time_seconds'));
    }
}
