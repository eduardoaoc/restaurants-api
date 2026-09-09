<?php

namespace Tests\Feature\Operations;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\InteractsWithOrders;
use Tests\Concerns\InteractsWithPayments;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bloco 5 — sales.received_today respects the Restaurant's OWN timezone
 * (RestaurantSettings.timezone), never the server/app timezone (always
 * UTC). Uses fixed instants via Carbon::setTestNow() — never the real
 * clock.
 */
class LiveSnapshotSalesTodayTest extends TestCase
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

    public function test_payment_just_before_local_midnight_is_still_todays_and_one_after_is_not(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $restaurant->settings()->update(['timezone' => 'Europe/Madrid']);
        $tableYesterday = $this->createTable($restaurant);
        $tableToday = $this->createTable($restaurant);
        $restaurantProduct = $this->createRestaurantProduct($restaurant, $this->createProduct($organization), 100.0);

        // 2026-06-15 23:50 Europe/Madrid == 2026-06-15 21:50 UTC (CEST, +2).
        Carbon::setTestNow(Carbon::parse('2026-06-15 21:50:00', 'UTC'));
        $sessionYesterday = $this->openSession($tableYesterday, $owner);
        $orderYesterday = $this->createWaiterOrder($tableYesterday, $owner, [
            ['restaurant_product_id' => $restaurantProduct->id, 'quantity' => 1],
        ]);
        $this->recordPayment($sessionYesterday, $owner, $orderYesterday->total);

        // 2026-06-16 00:10 Europe/Madrid == 2026-06-15 22:10 UTC — a new
        // local day has started even though the UTC calendar date has not.
        Carbon::setTestNow(Carbon::parse('2026-06-15 22:10:00', 'UTC'));
        $sessionToday = $this->openSession($tableToday, $owner);
        $orderToday = $this->createWaiterOrder($tableToday, $owner, [
            ['restaurant_product_id' => $restaurantProduct->id, 'quantity' => 1],
        ]);
        $this->recordPayment($sessionToday, $owner, $orderToday->total);

        // Snapshot generated at the same moment as the second payment.
        $response = $this->actingAs($owner, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/operations/live")
            ->assertOk();

        $this->assertSame('100.00', $response->json('data.summary.sales.received_today'));
    }

    public function test_no_payments_today_returns_zero(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $restaurant->settings()->update(['timezone' => 'Europe/Madrid']);

        Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00', 'UTC'));

        $this->actingAs($owner, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/operations/live")
            ->assertOk()
            ->assertJsonPath('data.summary.sales.received_today', '0.00');
    }
}
