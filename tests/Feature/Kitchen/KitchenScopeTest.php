<?php

namespace Tests\Feature\Kitchen;

use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithOrders;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class KitchenScopeTest extends TestCase
{
    use InteractsWithOrders, InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    // --- Organization isolation --------------------------------------

    public function test_other_organization_orders_never_appear_in_the_kds_queue(): void
    {
        [$organizationA, $ownerA, $restaurantA, $rpA] = $this->createTenantWithRestaurantProduct();
        $tableA = $this->createTable($restaurantA);
        $this->openSession($tableA, $ownerA);
        $orderA = $this->createWaiterOrder($tableA, $ownerA, [['restaurant_product_id' => $rpA->id, 'quantity' => 1]]);

        [, $ownerB, $restaurantB, $rpB] = $this->createTenantWithRestaurantProduct();
        $tableB = $this->createTable($restaurantB);
        $this->openSession($tableB, $ownerB);
        $this->createWaiterOrder($tableB, $ownerB, [['restaurant_product_id' => $rpB->id, 'quantity' => 1]]);

        $response = $this->actingAs($ownerA, 'web')->getJson('/api/v1/kitchen/orders')->assertOk();

        $ids = collect($response->json('data.orders'))->pluck('id');
        $this->assertTrue($ids->contains($orderA->id));
        $this->assertCount(1, $ids);
    }

    public function test_accept_order_of_another_organization_returns_404(): void
    {
        [$organizationA, $ownerA, $restaurantA] = $this->createTenant();
        $kitchenA = $this->createStaff($organizationA, $restaurantA, 'kitchen', 'K-A');

        [, $ownerB, $restaurantB, $rpB] = $this->createTenantWithRestaurantProduct();
        $tableB = $this->createTable($restaurantB);
        $this->openSession($tableB, $ownerB);
        $orderB = $this->createWaiterOrder($tableB, $ownerB, [['restaurant_product_id' => $rpB->id, 'quantity' => 1]]);

        $this->actingAs($kitchenA, 'web')
            ->postJson("/api/v1/orders/{$orderB->id}/accept")
            ->assertStatus(404);
    }

    // --- Cross-restaurant isolation within the same organization --------

    public function test_kitchen_a_queue_contains_only_its_own_restaurant(): void
    {
        [$organization, $owner, $restaurantA, $rpA] = $this->createTenantWithRestaurantProduct();
        $kitchenA = $this->createStaff($organization, $restaurantA, 'kitchen', 'K-A');
        $tableA = $this->createTable($restaurantA);
        $this->openSession($tableA, $owner);
        $orderA = $this->createWaiterOrder($tableA, $owner, [['restaurant_product_id' => $rpA->id, 'quantity' => 1]]);

        $restaurantB = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $productB = $this->createProduct($organization);
        $rpB = $this->createRestaurantProduct($restaurantB, $productB);
        $tableB = $this->createTable($restaurantB);
        $this->openSession($tableB, $owner);
        $this->createWaiterOrder($tableB, $owner, [['restaurant_product_id' => $rpB->id, 'quantity' => 1]]);

        $response = $this->actingAs($kitchenA, 'web')->getJson('/api/v1/kitchen/orders')->assertOk();

        $ids = collect($response->json('data.orders'))->pluck('id');
        $this->assertSame([$orderA->id], $ids->all());
    }

    public function test_kitchen_a_cannot_transition_order_of_restaurant_b(): void
    {
        [$organization, $owner, $restaurantA] = $this->createTenant();
        $kitchenA = $this->createStaff($organization, $restaurantA, 'kitchen', 'K-A');

        $restaurantB = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $productB = $this->createProduct($organization);
        $rpB = $this->createRestaurantProduct($restaurantB, $productB);
        $tableB = $this->createTable($restaurantB);
        $this->openSession($tableB, $owner);
        $orderB = $this->createWaiterOrder($tableB, $owner, [['restaurant_product_id' => $rpB->id, 'quantity' => 1]]);

        $this->actingAs($kitchenA, 'web')
            ->postJson("/api/v1/orders/{$orderB->id}/accept")
            ->assertStatus(404);
    }

    // --- Scope vs permission (must be tested independently) -------------

    public function test_kitchen_in_scope_but_missing_permission_gets_403_not_404(): void
    {
        [$organization, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        // Cashier belongs to the SAME restaurant (in scope) but lacks
        // update_kitchen_status — this must be a 403, distinct from the
        // 404 an out-of-scope restaurant would produce.
        $cashier = $this->createStaff($organization, $restaurant, 'cashier', 'C-1');
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);
        $order = $this->createWaiterOrder($table, $owner, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);

        $this->actingAs($cashier, 'web')
            ->postJson("/api/v1/orders/{$order->id}/accept")
            ->assertStatus(403);
    }

    public function test_kds_view_without_any_kitchen_relevant_permission_is_forbidden(): void
    {
        [$organization] = $this->createTenant();

        $bystander = User::factory()->create();
        $organization->users()->attach($bystander->id);

        $this->actingAs($bystander, 'web')
            ->getJson('/api/v1/kitchen/orders')
            ->assertStatus(403);
    }

    // --- Owner scope --------------------------------------------------

    public function test_owner_can_accept_orders_in_any_restaurant_of_their_organization(): void
    {
        [$organization, $owner, $restaurantA, $rpA] = $this->createTenantWithRestaurantProduct();
        $tableA = $this->createTable($restaurantA);
        $this->openSession($tableA, $owner);
        $orderA = $this->createWaiterOrder($tableA, $owner, [['restaurant_product_id' => $rpA->id, 'quantity' => 1]]);

        $restaurantB = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $productB = $this->createProduct($organization);
        $rpB = $this->createRestaurantProduct($restaurantB, $productB);
        $tableB = $this->createTable($restaurantB);
        $this->openSession($tableB, $owner);
        $orderB = $this->createWaiterOrder($tableB, $owner, [['restaurant_product_id' => $rpB->id, 'quantity' => 1]]);

        $this->actingAs($owner, 'web')->postJson("/api/v1/orders/{$orderA->id}/accept")->assertOk();
        $this->actingAs($owner, 'web')->postJson("/api/v1/orders/{$orderB->id}/accept")->assertOk();
    }

    public function test_owner_cannot_operate_a_different_organization_even_with_full_permissions(): void
    {
        [, $ownerA] = $this->createTenant();
        [, , $restaurantB, $rpB] = $this->createTenantWithRestaurantProduct();
        $ownerB = User::factory()->create();
        // ownerB belongs only to organization B — ownerA's TenantContext
        // resolves organization A regardless of what ownerA "could" do.
        $tableB = $this->createTable($restaurantB);
        $this->openSession($tableB, $ownerB);
        $orderB = $this->createWaiterOrder($tableB, $ownerB, [['restaurant_product_id' => $rpB->id, 'quantity' => 1]]);

        $this->actingAs($ownerA, 'web')
            ->postJson("/api/v1/orders/{$orderB->id}/accept")
            ->assertStatus(404);
    }

    // --- KDS restaurant_id filter -----------------------------------

    public function test_staff_filtering_by_another_restaurant_gets_404(): void
    {
        [$organization, $owner, $restaurantA] = $this->createTenant();
        $kitchenA = $this->createStaff($organization, $restaurantA, 'kitchen', 'K-A');
        $restaurantB = Restaurant::factory()->create(['organization_id' => $organization->id]);

        $this->actingAs($kitchenA, 'web')
            ->getJson("/api/v1/kitchen/orders?restaurant_id={$restaurantB->id}")
            ->assertStatus(404);
    }

    public function test_owner_filtering_by_restaurant_of_another_organization_gets_404(): void
    {
        [, $ownerA] = $this->createTenant();
        [, , $restaurantB] = $this->createTenant();

        $this->actingAs($ownerA, 'web')
            ->getJson("/api/v1/kitchen/orders?restaurant_id={$restaurantB->id}")
            ->assertStatus(404);
    }

    public function test_owner_filtering_by_own_organization_restaurant_is_allowed(): void
    {
        [$organization, $owner, $restaurantA, $rpA] = $this->createTenantWithRestaurantProduct();
        $tableA = $this->createTable($restaurantA);
        $this->openSession($tableA, $owner);
        $orderA = $this->createWaiterOrder($tableA, $owner, [['restaurant_product_id' => $rpA->id, 'quantity' => 1]]);

        $response = $this->actingAs($owner, 'web')
            ->getJson("/api/v1/kitchen/orders?restaurant_id={$restaurantA->id}")
            ->assertOk();

        $this->assertSame([$orderA->id], collect($response->json('data.orders'))->pluck('id')->all());
    }
}
