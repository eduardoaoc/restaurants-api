<?php

namespace Tests\Feature\Tenancy;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Explicit cross-tenant security scenario for the catalog:
 *
 * Organization A / Restaurant A / Product A
 * Organization B / Restaurant B / Product B
 *
 * User A must never read/update Product B, nor attach Product B to
 * Restaurant A, nor attach Product A to Restaurant B.
 */
class CatalogApiIsolationTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    public function test_user_a_cannot_view_product_b(): void
    {
        [, $ownerA] = $this->createTenant();
        [$organizationB] = $this->createTenant();
        $productB = $this->createProduct($organizationB);

        $this->actingAs($ownerA, 'web')
            ->getJson("/api/v1/products/{$productB->id}")
            ->assertNotFound();
    }

    public function test_user_a_cannot_update_product_b(): void
    {
        [, $ownerA] = $this->createTenant();
        [$organizationB] = $this->createTenant();
        $productB = $this->createProduct($organizationB);

        $this->actingAs($ownerA, 'web')
            ->patchJson("/api/v1/products/{$productB->id}", ['internal_name' => 'Pwned'])
            ->assertNotFound();

        $this->assertDatabaseMissing('products', ['internal_name' => 'Pwned']);
    }

    public function test_user_a_cannot_attach_product_b_to_restaurant_a(): void
    {
        [, $ownerA, $restaurantA] = $this->createTenant();
        [$organizationB] = $this->createTenant();
        $productB = $this->createProduct($organizationB);

        $this->actingAs($ownerA, 'web')
            ->postJson("/api/v1/restaurants/{$restaurantA->id}/products", [
                'product_id' => $productB->id,
                'price' => 3.0,
            ])
            ->assertUnprocessable();

        $this->assertDatabaseMissing('restaurant_products', ['product_id' => $productB->id]);
    }

    public function test_user_a_cannot_attach_product_a_to_restaurant_b(): void
    {
        [$organizationA, $ownerA] = $this->createTenant();
        [, , $restaurantB] = $this->createTenant();
        $productA = $this->createProduct($organizationA);

        $this->actingAs($ownerA, 'web')
            ->postJson("/api/v1/restaurants/{$restaurantB->id}/products", [
                'product_id' => $productA->id,
                'price' => 3.0,
            ])
            ->assertNotFound();

        $this->assertDatabaseMissing('restaurant_products', ['product_id' => $productA->id]);
    }

    public function test_user_a_cannot_edit_restaurant_product_b(): void
    {
        [, $ownerA] = $this->createTenant();
        [$organizationB, , $restaurantB] = $this->createTenant();
        $productB = $this->createProduct($organizationB);
        $restaurantProductB = $this->createRestaurantProduct($restaurantB, $productB, 5.0);

        $this->actingAs($ownerA, 'web')
            ->patchJson("/api/v1/restaurant-products/{$restaurantProductB->id}", ['price' => 1.0])
            ->assertNotFound();

        $this->assertDatabaseHas('restaurant_products', ['id' => $restaurantProductB->id, 'price' => 5.0]);
    }

    public function test_user_a_cannot_view_categories_of_restaurant_b(): void
    {
        [, $ownerA] = $this->createTenant();
        [, , $restaurantB] = $this->createTenant();
        $menuB = $this->createMenu($restaurantB);
        $this->createCategory($menuB);

        $this->actingAs($ownerA, 'web')
            ->getJson("/api/v1/restaurants/{$restaurantB->id}/categories")
            ->assertNotFound();
    }
}
