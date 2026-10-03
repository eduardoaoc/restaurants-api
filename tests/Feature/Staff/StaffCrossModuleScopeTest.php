<?php

namespace Tests\Feature\Staff;

use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithOrders;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Proves RestaurantScope's new restaurant_users-backed source works
 * end-to-end for a staff member with 2+ restaurants (Carlos -> A+B) across
 * a real operational module (Orders), not just in isolation — see
 * RestaurantScopeTest for the unit-level coverage of
 * accessibleRestaurantIds() itself.
 */
class StaffCrossModuleScopeTest extends TestCase
{
    use InteractsWithOrders, InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    public function test_staff_in_a_and_b_can_operate_orders_in_both_but_not_in_c(): void
    {
        [$organization, $owner, $restaurantA] = $this->createTenant();
        $restaurantB = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $restaurantC = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $carlos = $this->createStaffAcrossRestaurants($organization, [$restaurantA, $restaurantB], 'waiter', $owner);

        $tableA = $this->createTable($restaurantA);
        $tableB = $this->createTable($restaurantB);
        $tableC = $this->createTable($restaurantC);
        $this->openSession($tableA, $owner);
        $this->openSession($tableB, $owner);
        $this->openSession($tableC, $owner);

        $rpA = $this->createRestaurantProduct($restaurantA, $this->createProduct($organization));
        $rpB = $this->createRestaurantProduct($restaurantB, $this->createProduct($organization));
        $rpC = $this->createRestaurantProduct($restaurantC, $this->createProduct($organization));

        $orderA = $this->createWaiterOrder($tableA, $carlos, [['restaurant_product_id' => $rpA->id, 'quantity' => 1]]);
        $orderB = $this->createWaiterOrder($tableB, $carlos, [['restaurant_product_id' => $rpB->id, 'quantity' => 1]]);

        // Carlos can view/operate on orders of A and B.
        $this->actingAs($carlos, 'web')->getJson("/api/v1/orders/{$orderA->id}")->assertOk();
        $this->actingAs($carlos, 'web')->getJson("/api/v1/orders/{$orderB->id}")->assertOk();

        // Carlos cannot even create a waiter order against C's table —
        // TableController::show()/general restaurant scoping applies via
        // the same RestaurantScope, exercised here through the KDS list.
        $kds = $this->actingAs($carlos, 'web')->getJson('/api/v1/kitchen/orders')->assertOk();
        $ids = collect($kds->json('data.orders'))->pluck('id');
        $this->assertTrue($ids->contains($orderA->id));
        $this->assertTrue($ids->contains($orderB->id));

        $this->actingAs($carlos, 'web')
            ->getJson("/api/v1/kitchen/orders?restaurant_id={$restaurantC->id}")
            ->assertStatus(404);

        // A waiter order against restaurant C's table, created by the
        // owner directly, still resolves 404 for Carlos.
        $orderC = $this->createWaiterOrder($tableC, $owner, [['restaurant_product_id' => $rpC->id, 'quantity' => 1]]);
        $this->actingAs($carlos, 'web')->getJson("/api/v1/orders/{$orderC->id}")->assertStatus(404);
    }

    public function test_staff_in_a_and_b_dashboard_access_and_isolation(): void
    {
        [$organization, $owner, $restaurantA] = $this->createTenant();
        $restaurantB = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $restaurantC = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $carlos = $this->createStaffAcrossRestaurants($organization, [$restaurantA, $restaurantB], 'manager', $owner);

        $this->actingAs($carlos, 'web')->getJson("/api/v1/restaurants/{$restaurantA->id}/dashboard")->assertOk();
        $this->actingAs($carlos, 'web')->getJson("/api/v1/restaurants/{$restaurantB->id}/dashboard")->assertOk();
        $this->actingAs($carlos, 'web')->getJson("/api/v1/restaurants/{$restaurantC->id}/dashboard")->assertStatus(404);
    }
}
