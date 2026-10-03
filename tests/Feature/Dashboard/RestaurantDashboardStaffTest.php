<?php

namespace Tests\Feature\Dashboard;

use App\Models\Order;
use App\Models\Organization;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithOrders;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * staff.top_by_orders_served is a purely factual ordering by orders served
 * in the period — never a performance score — capped at 5, ties broken by
 * staff_user_id ascending. See RestaurantDashboardService::staff().
 */
class RestaurantDashboardStaffTest extends TestCase
{
    use InteractsWithOrders, InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    /**
     * Serve $count orders for $servedBy, creating a fresh table/session per
     * order (a session can only have one order in flight at a time here,
     * but multiple orders once each is served).
     */
    private function serveOrders(Restaurant $restaurant, Organization $organization, User $createdBy, User $servedBy, int $count): void
    {
        $rp = $this->createRestaurantProduct($restaurant, $this->createProduct($organization));
        $table = $this->createTable($restaurant, 'T-'.uniqid());
        $this->openSession($table, $createdBy);

        for ($i = 0; $i < $count; $i++) {
            $order = $this->createWaiterOrder($table, $createdBy, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);
            $this->advanceOrderTo($order, Order::STATUS_SERVED, $createdBy, $servedBy);
        }
    }

    public function test_staff_are_ordered_by_orders_served_descending(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $staffA = $this->createStaff($organization, $restaurant, 'waiter', 'W-A');
        $staffB = $this->createStaff($organization, $restaurant, 'waiter', 'W-B');

        $this->serveOrders($restaurant, $organization, $owner, $staffA, 5);
        $this->serveOrders($restaurant, $organization, $owner, $staffB, 3);

        $response = $this->actingAs($owner, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/dashboard")
            ->assertOk();

        $top = $response->json('data.dashboard.staff.top_by_orders_served');

        $this->assertSame($staffA->id, $top[0]['staff']['id']);
        $this->assertSame(5, $top[0]['orders_served']);
        $this->assertSame($staffB->id, $top[1]['staff']['id']);
        $this->assertSame(3, $top[1]['orders_served']);
    }

    public function test_top_is_capped_at_five_with_ties_broken_by_staff_id_ascending(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();

        $staff = [];
        for ($i = 0; $i < 6; $i++) {
            $staff[] = $this->createStaff($organization, $restaurant, 'waiter', 'W-'.$i);
        }

        // Every one of the 6 staff members serves exactly 2 orders — a full
        // tie, so only the cap + tie-break determine who is excluded.
        foreach ($staff as $waiter) {
            $this->serveOrders($restaurant, $organization, $owner, $waiter, 2);
        }

        $response = $this->actingAs($owner, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/dashboard")
            ->assertOk();

        $top = $response->json('data.dashboard.staff.top_by_orders_served');
        $this->assertCount(5, $top);

        $expectedIds = collect($staff)->pluck('id')->sort()->take(5)->values()->all();
        $this->assertSame($expectedIds, collect($top)->pluck('staff.id')->all());

        $excludedId = collect($staff)->pluck('id')->sort()->values()->last();
        $this->assertFalse(collect($top)->pluck('staff.id')->contains($excludedId));
    }

    public function test_staff_with_no_served_orders_in_the_period_does_not_appear(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $this->createStaff($organization, $restaurant, 'waiter', 'W-1');

        $this->actingAs($owner, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/dashboard")
            ->assertOk()
            ->assertJsonPath('data.dashboard.staff.top_by_orders_served', []);
    }
}
