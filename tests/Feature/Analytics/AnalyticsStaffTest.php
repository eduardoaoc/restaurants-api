<?php

namespace Tests\Feature\Analytics;

use App\Actions\Tables\AssignWaiterAction;
use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\InteractsWithOrders;
use Tests\Concerns\InteractsWithStaffShifts;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bloco 6 — staff analytics: the roster is defined by StaffShift overlap
 * with the period (same overlap math as occupancy), enriched with
 * StaffPerformanceService metrics — bulk-fetched, never one query set per
 * staff member. sales_attributed/guests_served are deliberately never
 * present on these entries (see StaffAnalytics docblock).
 */
class AnalyticsStaffTest extends TestCase
{
    use InteractsWithOrders, InteractsWithStaffShifts, InteractsWithTenants, RefreshDatabase;

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

    public function test_shift_overlapping_the_period_is_included_with_correct_active_seconds(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $restaurant->settings()->update(['timezone' => 'UTC']);
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1', name: 'Mateo');

        Carbon::setTestNow(Carbon::parse('2026-09-01 08:00:00', 'UTC'));
        $shift = $this->startShift($restaurant, $waiter, $waiter);
        Carbon::setTestNow(Carbon::parse('2026-09-01 12:00:00', 'UTC'));
        $this->endShift($shift, $waiter);
        Carbon::setTestNow();

        $response = $this->actingAs($owner, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/analytics?from=2026-09-01&to=2026-09-01")
            ->assertOk();

        $entry = collect($response->json('data.staff'))->firstWhere('user.id', $waiter->id);
        $this->assertNotNull($entry);
        $this->assertSame('Mateo', $entry['user']['name']);
        $this->assertSame('waiter', $entry['role']);
        $this->assertSame(1, $entry['shift_count']);
        $this->assertSame(4 * 3600, $entry['active_seconds']);
        $this->assertArrayNotHasKey('sales_attributed', $entry);
        $this->assertArrayNotHasKey('guests_served', $entry);
    }

    public function test_shift_starting_before_the_period_is_clamped(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $restaurant->settings()->update(['timezone' => 'UTC']);
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');

        Carbon::setTestNow(Carbon::parse('2026-08-31 22:00:00', 'UTC'));
        $shift = $this->startShift($restaurant, $waiter, $waiter);
        Carbon::setTestNow(Carbon::parse('2026-09-01 02:00:00', 'UTC'));
        $this->endShift($shift, $waiter);
        Carbon::setTestNow();

        $response = $this->actingAs($owner, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/analytics?from=2026-09-01&to=2026-09-01")
            ->assertOk();

        $entry = collect($response->json('data.staff'))->firstWhere('user.id', $waiter->id);
        $this->assertSame(2 * 3600, $entry['active_seconds']);
    }

    public function test_shift_entirely_outside_the_period_is_excluded(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');

        Carbon::setTestNow(Carbon::parse('2026-01-01 08:00:00', 'UTC'));
        $shift = $this->startShift($restaurant, $waiter, $waiter);
        Carbon::setTestNow(Carbon::parse('2026-01-01 12:00:00', 'UTC'));
        $this->endShift($shift, $waiter);
        Carbon::setTestNow();

        $response = $this->actingAs($owner, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/analytics?from=2026-09-01&to=2026-09-01")
            ->assertOk();

        $names = collect($response->json('data.staff'))->pluck('user.id')->all();
        $this->assertNotContains($waiter->id, $names);
    }

    public function test_performance_metrics_are_included_alongside_the_shift_roster(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $restaurant->settings()->update(['timezone' => 'UTC']);
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');

        Carbon::setTestNow(Carbon::parse('2026-09-01 08:00:00', 'UTC'));
        $this->startShift($restaurant, $waiter, $waiter);

        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);
        app(AssignWaiterAction::class)->execute($session, $waiter, $owner);

        $restaurantProduct = $this->createRestaurantProduct($restaurant, $this->createProduct($organization), 10.0);
        $order = $this->createWaiterOrder($table, $waiter, [
            ['restaurant_product_id' => $restaurantProduct->id, 'quantity' => 1],
        ]);
        $this->advanceOrderTo($order, 'served', $waiter, servedBy: $waiter);
        Carbon::setTestNow();

        $response = $this->actingAs($owner, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/analytics?from=2026-09-01&to=2026-09-01")
            ->assertOk();

        $entry = collect($response->json('data.staff'))->firstWhere('user.id', $waiter->id);
        $this->assertSame(1, $entry['tables_served']);
        $this->assertSame(1, $entry['orders_created']);
        $this->assertSame(1, $entry['orders_served']);
    }

    public function test_staff_from_another_restaurant_never_leaks_into_this_restaurants_analytics(): void
    {
        [$organization, $owner, $restaurantA] = $this->createTenant();
        $restaurantB = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $waiterB = $this->createStaff($organization, $restaurantB, 'waiter', 'W-B');

        Carbon::setTestNow(Carbon::parse('2026-09-01 08:00:00', 'UTC'));
        $this->startShift($restaurantB, $waiterB, $waiterB);
        Carbon::setTestNow();

        $response = $this->actingAs($owner, 'web')
            ->getJson("/api/v1/restaurants/{$restaurantA->id}/analytics?from=2026-09-01&to=2026-09-01")
            ->assertOk();

        $ids = collect($response->json('data.staff'))->pluck('user.id')->all();
        $this->assertNotContains($waiterB->id, $ids);
    }
}
