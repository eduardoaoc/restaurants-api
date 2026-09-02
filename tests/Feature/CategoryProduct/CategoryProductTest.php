<?php

namespace Tests\Feature\CategoryProduct;

use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class CategoryProductTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    public function test_owner_can_add_a_restaurant_product_to_a_category(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $menu = $this->createMenu($restaurant);
        $category = $this->createCategory($menu);
        $product = $this->createProduct($organization);
        $restaurantProduct = $this->createRestaurantProduct($restaurant, $product);

        $this->actingAs($owner, 'web')
            ->postJson("/api/v1/categories/{$category->id}/products", [
                'restaurant_product_id' => $restaurantProduct->id,
                'sort_order' => 10,
            ])
            ->assertCreated()
            ->assertJsonPath('data.category_product.category_id', $category->id)
            ->assertJsonPath('data.category_product.restaurant_product_id', $restaurantProduct->id)
            ->assertJsonPath('data.category_product.sort_order', 10);
    }

    public function test_sort_order_can_be_updated(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $menu = $this->createMenu($restaurant);
        $category = $this->createCategory($menu);
        $product = $this->createProduct($organization);
        $restaurantProduct = $this->createRestaurantProduct($restaurant, $product);

        $category->categoryProducts()->create([
            'restaurant_product_id' => $restaurantProduct->id,
            'sort_order' => 1,
        ]);

        $this->actingAs($owner, 'web')
            ->patchJson("/api/v1/categories/{$category->id}/products/{$restaurantProduct->id}", ['sort_order' => 99])
            ->assertOk()
            ->assertJsonPath('data.category_product.sort_order', 99);
    }

    public function test_a_restaurant_product_from_another_restaurant_is_rejected(): void
    {
        [$organization, $owner, $restaurantA] = $this->createTenant();
        $restaurantB = Restaurant::factory()->create(['organization_id' => $organization->id]);

        $menuA = $this->createMenu($restaurantA);
        $categoryA = $this->createCategory($menuA);

        $product = $this->createProduct($organization);
        $restaurantProductB = $this->createRestaurantProduct($restaurantB, $product);

        $this->actingAs($owner, 'web')
            ->postJson("/api/v1/categories/{$categoryA->id}/products", [
                'restaurant_product_id' => $restaurantProductB->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('restaurant_product_id');

        $this->assertDatabaseMissing('category_products', ['restaurant_product_id' => $restaurantProductB->id]);
    }

    public function test_duplicate_link_is_rejected(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $menu = $this->createMenu($restaurant);
        $category = $this->createCategory($menu);
        $product = $this->createProduct($organization);
        $restaurantProduct = $this->createRestaurantProduct($restaurant, $product);

        $category->categoryProducts()->create(['restaurant_product_id' => $restaurantProduct->id]);

        $this->actingAs($owner, 'web')
            ->postJson("/api/v1/categories/{$category->id}/products", [
                'restaurant_product_id' => $restaurantProduct->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('restaurant_product_id');
    }

    public function test_user_without_manage_menu_permission_receives_forbidden(): void
    {
        [$organization, , $restaurant] = $this->createTenant();
        $menu = $this->createMenu($restaurant);
        $category = $this->createCategory($menu);
        $product = $this->createProduct($organization);
        $restaurantProduct = $this->createRestaurantProduct($restaurant, $product);
        $kitchen = $this->createStaff($organization, $restaurant, 'kitchen', 'K-1');

        $this->actingAs($kitchen, 'web')
            ->postJson("/api/v1/categories/{$category->id}/products", [
                'restaurant_product_id' => $restaurantProduct->id,
            ])
            ->assertForbidden();
    }
}
