<?php

namespace Tests\Feature\Analytics;

use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithOrders;
use Tests\Concerns\InteractsWithPayments;
use Tests\Concerns\InteractsWithStaffShifts;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bloco 6 — Restaurant A's analytics must never include Restaurant B's
 * data, even within the SAME organization (same owner, in-scope for
 * both): revenue, sessions/occupancy, products, and staff are all
 * checked independently.
 */
class AnalyticsIsolationTest extends TestCase
{
    use InteractsWithOrders, InteractsWithPayments, InteractsWithStaffShifts, InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    public function test_revenue_and_orders_from_another_restaurant_never_leak_in(): void
    {
        [$organization, $owner, $restaurantA] = $this->createTenant();
        $restaurantB = Restaurant::factory()->create(['organization_id' => $organization->id]);

        $tableB = $this->createTable($restaurantB);
        $sessionB = $this->openSession($tableB, $owner);
        $this->closeSessionWithFullPayment($sessionB, $owner);

        $response = $this->actingAs($owner, 'web')
            ->getJson("/api/v1/restaurants/{$restaurantA->id}/analytics?from=2026-01-01&to=2026-12-31")
            ->assertOk();

        $this->assertSame('0.00', $response->json('data.summary.revenue'));
        $this->assertSame(0, $response->json('data.summary.orders_count'));
        $this->assertSame(0, $response->json('data.orders.total'));
    }

    public function test_occupancy_and_turnover_from_another_restaurant_never_leak_in(): void
    {
        [$organization, $owner, $restaurantA] = $this->createTenant();
        $restaurantB = Restaurant::factory()->create(['organization_id' => $organization->id]);

        $tableB = $this->createTable($restaurantB);
        $sessionB = $this->openSession($tableB, $owner);
        $this->closeSessionWithFullPayment($sessionB, $owner);

        $response = $this->actingAs($owner, 'web')
            ->getJson("/api/v1/restaurants/{$restaurantA->id}/analytics?from=2026-01-01&to=2026-12-31")
            ->assertOk();

        $this->assertSame(0, $response->json('data.occupancy.occupied_seconds'));
        $this->assertSame(0, $response->json('data.table_turnover.closed_sessions'));
    }

    public function test_top_products_from_another_restaurant_never_leak_in(): void
    {
        [$organization, $owner, $restaurantA] = $this->createTenant();
        $restaurantB = Restaurant::factory()->create(['organization_id' => $organization->id]);

        $tableB = $this->createTable($restaurantB);
        $this->openSession($tableB, $owner);
        $product = $this->createProduct($organization, null, [['locale' => 'en', 'name' => 'Only In B']]);
        $restaurantProductB = $this->createRestaurantProduct($restaurantB, $product, 10.0);
        $this->createWaiterOrder($tableB, $owner, [
            ['restaurant_product_id' => $restaurantProductB->id, 'quantity' => 1],
        ]);

        $response = $this->actingAs($owner, 'web')
            ->getJson("/api/v1/restaurants/{$restaurantA->id}/analytics?from=2026-01-01&to=2026-12-31")
            ->assertOk();

        $names = collect($response->json('data.products.top_by_quantity'))->pluck('name')->all();
        $this->assertNotContains('Only In B', $names);
    }

    public function test_staff_shift_from_another_restaurant_never_leaks_in(): void
    {
        [$organization, $owner, $restaurantA] = $this->createTenant();
        $restaurantB = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $waiterB = $this->createStaff($organization, $restaurantB, 'waiter', 'W-B');

        $this->startShift($restaurantB, $waiterB, $waiterB);

        $response = $this->actingAs($owner, 'web')
            ->getJson("/api/v1/restaurants/{$restaurantA->id}/analytics")
            ->assertOk();

        $ids = collect($response->json('data.staff'))->pluck('user.id')->all();
        $this->assertNotContains($waiterB->id, $ids);
    }
}
