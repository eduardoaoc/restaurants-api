<?php

namespace Tests\Feature\Analytics;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\InteractsWithOrders;
use Tests\Concerns\InteractsWithPayments;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bloco 6 — revenue = PaymentRecord actually received. Partial payments,
 * multiple payments per session, payments outside the period, and Order
 * date irrelevance are all covered explicitly (items 51/52/68).
 */
class AnalyticsRevenueTest extends TestCase
{
    use InteractsWithOrders, InteractsWithPayments, InteractsWithTenants, RefreshDatabase;

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

    public function test_partial_payment_counts_only_the_amount_actually_received(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);
        $restaurantProduct = $this->createRestaurantProduct($restaurant, $this->createProduct($organization), 100.0);
        $this->createWaiterOrder($table, $owner, [
            ['restaurant_product_id' => $restaurantProduct->id, 'quantity' => 1],
        ]);
        $this->recordPayment($session, $owner, '40.00');

        $this->actingAs($owner, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/analytics?from=2026-01-01&to=2026-12-31")
            ->assertOk()
            ->assertJsonPath('data.summary.revenue', '40.00');
    }

    public function test_multiple_payments_on_the_same_session_all_count(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);
        $restaurantProduct = $this->createRestaurantProduct($restaurant, $this->createProduct($organization), 100.0);
        $this->createWaiterOrder($table, $owner, [
            ['restaurant_product_id' => $restaurantProduct->id, 'quantity' => 1],
        ]);
        $this->recordPayment($session, $owner, '30.00');
        $this->recordPayment($session, $owner, '20.00');

        $this->actingAs($owner, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/analytics?from=2026-01-01&to=2026-12-31")
            ->assertOk()
            ->assertJsonPath('data.summary.revenue', '50.00')
            ->assertJsonPath('data.summary.sessions_with_payments', 1);
    }

    public function test_payments_outside_the_period_are_excluded(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);
        $restaurantProduct = $this->createRestaurantProduct($restaurant, $this->createProduct($organization), 100.0);
        $this->createWaiterOrder($table, $owner, [
            ['restaurant_product_id' => $restaurantProduct->id, 'quantity' => 1],
        ]);

        Carbon::setTestNow(Carbon::parse('2026-03-15 12:00:00', 'UTC'));
        $this->recordPayment($session, $owner, '25.00');
        Carbon::setTestNow();

        $this->actingAs($owner, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/analytics?from=2026-04-01&to=2026-04-30")
            ->assertOk()
            ->assertJsonPath('data.summary.revenue', '0.00');
    }

    public function test_order_creation_date_is_irrelevant_to_revenue_recognition(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);
        $restaurantProduct = $this->createRestaurantProduct($restaurant, $this->createProduct($organization), 60.0);

        // Order created in August...
        Carbon::setTestNow(Carbon::parse('2026-08-20 12:00:00', 'UTC'));
        $this->createWaiterOrder($table, $owner, [
            ['restaurant_product_id' => $restaurantProduct->id, 'quantity' => 1],
        ]);

        // ...payment recorded in September.
        Carbon::setTestNow(Carbon::parse('2026-09-05 12:00:00', 'UTC'));
        $this->recordPayment($session, $owner, '60.00');
        Carbon::setTestNow();

        // September's revenue includes the payment...
        $this->actingAs($owner, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/analytics?from=2026-09-01&to=2026-09-30")
            ->assertOk()
            ->assertJsonPath('data.summary.revenue', '60.00');

        // ...August's does not, even though the order itself was placed then.
        $this->actingAs($owner, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/analytics?from=2026-08-01&to=2026-08-31")
            ->assertOk()
            ->assertJsonPath('data.summary.revenue', '0.00');
    }

    public function test_money_precision_is_never_a_binary_float_error(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);
        $restaurantProduct = $this->createRestaurantProduct($restaurant, $this->createProduct($organization), 10.0);
        $this->createWaiterOrder($table, $owner, [
            ['restaurant_product_id' => $restaurantProduct->id, 'quantity' => 1],
        ]);
        // 0.10 + 0.20 famously != 0.30 in IEEE 754 float arithmetic.
        $this->recordPayment($session, $owner, '0.10');
        $this->recordPayment($session, $owner, '0.20');

        $this->actingAs($owner, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/analytics?from=2026-01-01&to=2026-12-31")
            ->assertOk()
            ->assertJsonPath('data.summary.revenue', '0.30');
    }
}
