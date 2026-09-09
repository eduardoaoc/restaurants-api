<?php

namespace Tests\Feature\Analytics;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\InteractsWithOrders;
use Tests\Concerns\InteractsWithPayments;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bloco 6 — revenue_series: zero-filled day/week/month buckets, and the
 * local timezone day boundary (a payment must land in the correct LOCAL
 * bucket, not the UTC one).
 */
class AnalyticsSeriesTest extends TestCase
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

    public function test_day_series_is_zero_filled_around_a_single_payment(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);
        $restaurantProduct = $this->createRestaurantProduct($restaurant, $this->createProduct($organization), 25.0);
        $this->createWaiterOrder($table, $owner, [
            ['restaurant_product_id' => $restaurantProduct->id, 'quantity' => 1],
        ]);

        Carbon::setTestNow(Carbon::parse('2026-09-02 12:00:00', 'UTC'));
        $this->recordPayment($session, $owner, '25.00');
        Carbon::setTestNow();

        $response = $this->actingAs($owner, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/analytics?from=2026-09-01&to=2026-09-03&granularity=day")
            ->assertOk();

        $series = collect($response->json('data.revenue_series'))->keyBy('period_start');
        $this->assertSame('0.00', $series->get('2026-09-01')['revenue']);
        $this->assertSame('25.00', $series->get('2026-09-02')['revenue']);
        $this->assertSame('0.00', $series->get('2026-09-03')['revenue']);
        $this->assertCount(3, $series);
    }

    public function test_timezone_boundary_places_payment_in_the_correct_local_day_bucket(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $restaurant->settings()->update(['timezone' => 'Europe/Madrid']);
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);
        $restaurantProduct = $this->createRestaurantProduct($restaurant, $this->createProduct($organization), 15.0);
        $this->createWaiterOrder($table, $owner, [
            ['restaurant_product_id' => $restaurantProduct->id, 'quantity' => 1],
        ]);

        // 22:10 UTC on 2026-06-15 = 00:10 local (2026-06-16) — must bucket
        // under the 16th locally, even though the UTC calendar date is
        // still the 15th.
        Carbon::setTestNow(Carbon::parse('2026-06-15 22:10:00', 'UTC'));
        $this->recordPayment($session, $owner, '15.00');
        Carbon::setTestNow();

        $response = $this->actingAs($owner, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/analytics?from=2026-06-15&to=2026-06-16&granularity=day")
            ->assertOk();

        $series = collect($response->json('data.revenue_series'))->keyBy('period_start');
        $this->assertSame('0.00', $series->get('2026-06-15')['revenue']);
        $this->assertSame('15.00', $series->get('2026-06-16')['revenue']);
    }

    public function test_month_granularity_buckets_by_calendar_month(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);
        $restaurantProduct = $this->createRestaurantProduct($restaurant, $this->createProduct($organization), 40.0);
        $this->createWaiterOrder($table, $owner, [
            ['restaurant_product_id' => $restaurantProduct->id, 'quantity' => 1],
        ]);

        Carbon::setTestNow(Carbon::parse('2026-09-20 12:00:00', 'UTC'));
        $this->recordPayment($session, $owner, '40.00');
        Carbon::setTestNow();

        $response = $this->actingAs($owner, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/analytics?from=2026-08-15&to=2026-10-05&granularity=month")
            ->assertOk();

        $series = collect($response->json('data.revenue_series'))->keyBy('period_start');
        $this->assertSame('0.00', $series->get('2026-08-01')['revenue']);
        $this->assertSame('40.00', $series->get('2026-09-01')['revenue']);
        $this->assertSame('0.00', $series->get('2026-10-01')['revenue']);
    }
}
