<?php

namespace Tests\Feature\Staff;

use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithOrders;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class StaffPerformanceShowTest extends TestCase
{
    use InteractsWithOrders, InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    public function test_owner_can_view_performance_of_a_staff_member(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');

        $this->actingAs($owner, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/staff/{$waiter->id}/performance")
            ->assertOk()
            ->assertJsonPath('data.performance.scope', 'restaurant')
            ->assertJsonPath('data.performance.staff.id', $waiter->id)
            ->assertJsonPath('data.performance.staff.restaurant.id', $restaurant->id);
    }

    public function test_target_metrics_are_isolated_to_the_requested_restaurant_even_when_owner_spans_many(): void
    {
        [$organization, $owner, $restaurantA] = $this->createTenant();
        $restaurantB = Restaurant::factory()->create(['organization_id' => $organization->id]);

        $waiterA = $this->createStaff($organization, $restaurantA, 'waiter', 'W-A');
        $waiterB = $this->createStaff($organization, $restaurantB, 'waiter', 'W-B');

        $tableA = $this->createTable($restaurantA);
        $tableB = $this->createTable($restaurantB);
        $this->openSession($tableA, $waiterA);
        $this->openSession($tableB, $waiterB);

        $rpA = $this->createRestaurantProduct($restaurantA, $this->createProduct($organization));
        $rpB = $this->createRestaurantProduct($restaurantB, $this->createProduct($organization));

        $this->createWaiterOrder($tableA, $waiterA, [['restaurant_product_id' => $rpA->id, 'quantity' => 1]]);
        $this->createWaiterOrder($tableB, $waiterB, [['restaurant_product_id' => $rpB->id, 'quantity' => 1]]);
        $this->createWaiterOrder($tableB, $waiterB, [['restaurant_product_id' => $rpB->id, 'quantity' => 1]]);

        // Owner (organization-wide) queries waiterA's performance: even
        // though the owner can reach both restaurants, waiterA's metrics
        // must reflect restaurant A only.
        $this->actingAs($owner, 'web')
            ->getJson("/api/v1/restaurants/{$restaurantA->id}/staff/{$waiterA->id}/performance")
            ->assertOk()
            ->assertJsonPath('data.performance.metrics.orders_created', 1)
            ->assertJsonPath('data.performance.staff.restaurant.id', $restaurantA->id);

        $this->actingAs($owner, 'web')
            ->getJson("/api/v1/restaurants/{$restaurantB->id}/staff/{$waiterB->id}/performance")
            ->assertOk()
            ->assertJsonPath('data.performance.metrics.orders_created', 2)
            ->assertJsonPath('data.performance.staff.restaurant.id', $restaurantB->id);
    }

    /**
     * A staff member in A+B, queried through restaurant A, must reflect A
     * only — even though the same staff member has more orders in B.
     */
    public function test_staff_in_two_restaurants_shows_only_the_requested_restaurants_metrics(): void
    {
        [$organization, $owner, $restaurantA] = $this->createTenant();
        $restaurantB = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $carlos = $this->createStaffAcrossRestaurants($organization, [$restaurantA, $restaurantB], 'waiter', $owner);

        $tableA = $this->createTable($restaurantA);
        $tableB = $this->createTable($restaurantB);
        $this->openSession($tableA, $carlos);
        $this->openSession($tableB, $carlos);

        $rpA = $this->createRestaurantProduct($restaurantA, $this->createProduct($organization));
        $rpB = $this->createRestaurantProduct($restaurantB, $this->createProduct($organization));

        $this->createWaiterOrder($tableA, $carlos, [['restaurant_product_id' => $rpA->id, 'quantity' => 1]]);
        $this->createWaiterOrder($tableB, $carlos, [['restaurant_product_id' => $rpB->id, 'quantity' => 1]]);
        $this->createWaiterOrder($tableB, $carlos, [['restaurant_product_id' => $rpB->id, 'quantity' => 1]]);

        $this->actingAs($owner, 'web')
            ->getJson("/api/v1/restaurants/{$restaurantA->id}/staff/{$carlos->id}/performance")
            ->assertOk()
            ->assertJsonPath('data.performance.metrics.orders_created', 1);

        $this->actingAs($owner, 'web')
            ->getJson("/api/v1/restaurants/{$restaurantB->id}/staff/{$carlos->id}/performance")
            ->assertOk()
            ->assertJsonPath('data.performance.metrics.orders_created', 2);
    }

    public function test_manager_scoped_to_one_restaurant_cannot_view_staff_of_another_restaurant(): void
    {
        [$organization, , $restaurantA] = $this->createTenant();
        $restaurantB = Restaurant::factory()->create(['organization_id' => $organization->id]);

        $manager = $this->createStaff($organization, $restaurantA, 'manager', 'M-A');
        $waiterB = $this->createStaff($organization, $restaurantB, 'waiter', 'W-B');

        $this->actingAs($manager, 'web')
            ->getJson("/api/v1/restaurants/{$restaurantB->id}/staff/{$waiterB->id}/performance")
            ->assertNotFound();
    }

    public function test_manager_scoped_to_one_restaurant_can_view_staff_of_that_restaurant(): void
    {
        [$organization, , $restaurant] = $this->createTenant();
        $manager = $this->createStaff($organization, $restaurant, 'manager', 'M-A');
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-A');

        $this->actingAs($manager, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/staff/{$waiter->id}/performance")
            ->assertOk();
    }

    public function test_staff_without_view_reports_permission_gets_forbidden(): void
    {
        [$organization, , $restaurant] = $this->createTenant();
        $waiterA = $this->createStaff($organization, $restaurant, 'waiter', 'W-A');
        $waiterB = $this->createStaff($organization, $restaurant, 'waiter', 'W-B');

        $this->actingAs($waiterA, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/staff/{$waiterB->id}/performance")
            ->assertForbidden();
    }

    public function test_staff_of_another_organization_is_not_found(): void
    {
        [$organization, $owner] = $this->createTenant();
        [$otherOrganization, , $otherRestaurant] = $this->createTenant();
        $otherStaff = $this->createStaff($otherOrganization, $otherRestaurant, 'waiter', 'W-1');

        $this->actingAs($owner, 'web')
            ->getJson("/api/v1/restaurants/{$otherRestaurant->id}/staff/{$otherStaff->id}/performance")
            ->assertNotFound();
    }

    /**
     * A staff member queried through a Restaurant they have no
     * restaurant_users link to at all — even one within the requester's
     * own organization/scope — is not found.
     */
    public function test_staff_with_no_link_to_the_requested_restaurant_is_not_found(): void
    {
        [$organization, $owner, $restaurantA] = $this->createTenant();
        $restaurantB = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $waiterA = $this->createStaff($organization, $restaurantA, 'waiter', 'W-A');

        $this->actingAs($owner, 'web')
            ->getJson("/api/v1/restaurants/{$restaurantB->id}/staff/{$waiterA->id}/performance")
            ->assertNotFound();
    }

    public function test_invalid_performance_period_is_rejected(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');

        $this->actingAs($owner, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/staff/{$waiter->id}/performance?from=not-a-date&to=2026-01-01")
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'INVALID_PERFORMANCE_PERIOD');
    }
}
