<?php

namespace Tests\Feature\Dashboard;

use App\Models\Order;
use App\Models\Organization;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithOrders;
use Tests\Concerns\InteractsWithPayments;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Multi-tenancy and permission contract for the dashboard endpoint:
 * outside the organization -> 404, outside RestaurantScope -> 404, in
 * scope without view_reports -> 403. Data isolation (section 98) is the
 * one mandatory test proving numbers never leak across restaurants, even
 * for an owner who can reach both.
 */
class RestaurantDashboardScopeTest extends TestCase
{
    use InteractsWithOrders, InteractsWithPayments, InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    public function test_owner_can_view_the_dashboard_of_any_restaurant_in_their_organization(): void
    {
        [$organization, $owner, $restaurantA] = $this->createTenant();
        $restaurantB = Restaurant::factory()->create(['organization_id' => $organization->id]);

        $this->actingAs($owner, 'web')->getJson("/api/v1/restaurants/{$restaurantA->id}/dashboard")->assertOk();
        $this->actingAs($owner, 'web')->getJson("/api/v1/restaurants/{$restaurantB->id}/dashboard")->assertOk();
    }

    public function test_restaurant_sales_never_mix_even_for_an_organization_wide_owner(): void
    {
        [$organization, $owner, $restaurantA] = $this->createTenant();
        $restaurantB = Restaurant::factory()->create(['organization_id' => $organization->id]);

        $this->payRestaurant($organization, $owner, $restaurantA, '100.00');
        $this->payRestaurant($organization, $owner, $restaurantB, '500.00');

        $this->actingAs($owner, 'web')
            ->getJson("/api/v1/restaurants/{$restaurantA->id}/dashboard")
            ->assertOk()
            ->assertJsonPath('data.dashboard.sales.total', '100.00');

        $this->actingAs($owner, 'web')
            ->getJson("/api/v1/restaurants/{$restaurantB->id}/dashboard")
            ->assertOk()
            ->assertJsonPath('data.dashboard.sales.total', '500.00');
    }

    public function test_manager_of_restaurant_a_gets_404_for_restaurant_b(): void
    {
        [$organization, , $restaurantA] = $this->createTenant();
        $restaurantB = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $managerA = $this->createStaff($organization, $restaurantA, 'manager', 'M-A');

        $this->actingAs($managerA, 'web')
            ->getJson("/api/v1/restaurants/{$restaurantB->id}/dashboard")
            ->assertStatus(404);
    }

    public function test_manager_of_restaurant_a_can_view_its_own_dashboard(): void
    {
        [$organization, , $restaurantA] = $this->createTenant();
        $managerA = $this->createStaff($organization, $restaurantA, 'manager', 'M-A');

        $this->actingAs($managerA, 'web')
            ->getJson("/api/v1/restaurants/{$restaurantA->id}/dashboard")
            ->assertOk();
    }

    public function test_same_restaurant_without_view_reports_permission_is_403(): void
    {
        [$organization, , $restaurant] = $this->createTenant();
        // Waiters are not seeded with view_reports.
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');

        $this->actingAs($waiter, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/dashboard")
            ->assertStatus(403);
    }

    public function test_restaurant_of_another_organization_is_404(): void
    {
        [, $ownerA] = $this->createTenant();
        [, , $restaurantB] = $this->createTenant();

        $this->actingAs($ownerA, 'web')
            ->getJson("/api/v1/restaurants/{$restaurantB->id}/dashboard")
            ->assertStatus(404);
    }

    /**
     * Records one order + full payment of $amount for $restaurant, acting
     * as $owner.
     */
    private function payRestaurant(Organization $organization, User $owner, Restaurant $restaurant, string $amount): void
    {
        $table = $this->createTable($restaurant, 'T-'.uniqid());
        $session = $this->openSession($table, $owner);
        $rp = $this->createRestaurantProduct($restaurant, $this->createProduct($organization), (float) $amount);
        $order = $this->createWaiterOrder($table, $owner, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);
        $this->advanceOrderTo($order, Order::STATUS_SERVED, $owner);
        $this->recordPayment($session, $owner, $amount);
    }
}
