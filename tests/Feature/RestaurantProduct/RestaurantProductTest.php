<?php

namespace Tests\Feature\RestaurantProduct;

use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class RestaurantProductTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    public function test_owner_can_add_a_product_to_a_restaurant(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $product = $this->createProduct($organization);

        $this->actingAs($owner, 'web')
            ->postJson("/api/v1/restaurants/{$restaurant->id}/products", [
                'product_id' => $product->id,
                'price' => 12.90,
                'available' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('data.restaurant_product.restaurant_id', $restaurant->id)
            ->assertJsonPath('data.restaurant_product.product_id', $product->id)
            ->assertJsonPath('data.restaurant_product.price', '12.90')
            ->assertJsonPath('data.restaurant_product.available', true);
    }

    public function test_available_defaults_to_true_and_can_be_updated(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $product = $this->createProduct($organization);

        $response = $this->actingAs($owner, 'web')
            ->postJson("/api/v1/restaurants/{$restaurant->id}/products", [
                'product_id' => $product->id,
                'price' => 5,
            ])
            ->assertCreated()
            ->assertJsonPath('data.restaurant_product.available', true);

        $restaurantProductId = $response->json('data.restaurant_product.id');

        $this->actingAs($owner, 'web')
            ->patchJson("/api/v1/restaurant-products/{$restaurantProductId}", ['available' => false])
            ->assertOk()
            ->assertJsonPath('data.restaurant_product.available', false);
    }

    public function test_the_same_product_can_be_added_to_multiple_restaurants_with_different_prices(): void
    {
        [$organization, $owner, $restaurantA] = $this->createTenant();
        $restaurantB = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $product = $this->createProduct($organization);

        $this->actingAs($owner, 'web')
            ->postJson("/api/v1/restaurants/{$restaurantA->id}/products", ['product_id' => $product->id, 'price' => 3.00])
            ->assertCreated()
            ->assertJsonPath('data.restaurant_product.price', '3.00');

        $this->actingAs($owner, 'web')
            ->postJson("/api/v1/restaurants/{$restaurantB->id}/products", ['product_id' => $product->id, 'price' => 3.50])
            ->assertCreated()
            ->assertJsonPath('data.restaurant_product.price', '3.50');

        $this->assertDatabaseCount('restaurant_products', 2);
    }

    public function test_duplicate_link_in_the_same_restaurant_is_rejected(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $product = $this->createProduct($organization);
        $this->createRestaurantProduct($restaurant, $product, 3.0);

        $this->actingAs($owner, 'web')
            ->postJson("/api/v1/restaurants/{$restaurant->id}/products", [
                'product_id' => $product->id,
                'price' => 4.0,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('product_id');
    }

    public function test_product_from_another_organization_is_rejected(): void
    {
        [, $ownerA, $restaurantA] = $this->createTenant();
        [$organizationB] = $this->createTenant();
        $productB = $this->createProduct($organizationB);

        $this->actingAs($ownerA, 'web')
            ->postJson("/api/v1/restaurants/{$restaurantA->id}/products", [
                'product_id' => $productB->id,
                'price' => 3.0,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('product_id');

        $this->assertDatabaseMissing('restaurant_products', ['product_id' => $productB->id]);
    }

    public function test_restaurant_from_another_organization_is_rejected(): void
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
    }

    public function test_restaurant_product_from_another_organization_returns_not_found(): void
    {
        [, $ownerA] = $this->createTenant();
        [$organizationB, , $restaurantB] = $this->createTenant();
        $productB = $this->createProduct($organizationB);
        $restaurantProductB = $this->createRestaurantProduct($restaurantB, $productB, 3.0);

        $this->actingAs($ownerA, 'web')
            ->patchJson("/api/v1/restaurant-products/{$restaurantProductB->id}", ['price' => 99])
            ->assertNotFound();
    }

    public function test_user_without_manage_products_permission_receives_forbidden(): void
    {
        [$organization, , $restaurant] = $this->createTenant();
        $product = $this->createProduct($organization);
        $kitchen = $this->createStaff($organization, $restaurant, 'kitchen', 'K-1');

        $this->actingAs($kitchen, 'web')
            ->postJson("/api/v1/restaurants/{$restaurant->id}/products", [
                'product_id' => $product->id,
                'price' => 3.0,
            ])
            ->assertForbidden();
    }
}
