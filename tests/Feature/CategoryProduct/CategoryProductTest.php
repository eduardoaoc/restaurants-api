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

    public function test_owner_can_list_the_products_of_a_category_ordered_by_sort_order(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $menu = $this->createMenu($restaurant);
        $category = $this->createCategory($menu);
        $productA = $this->createProduct($organization, 'Croquetas');
        $productB = $this->createProduct($organization, 'Bravas');
        $restaurantProductA = $this->createRestaurantProduct($restaurant, $productA);
        $restaurantProductB = $this->createRestaurantProduct($restaurant, $productB);

        $category->categoryProducts()->create(['restaurant_product_id' => $restaurantProductA->id, 'sort_order' => 20]);
        $category->categoryProducts()->create(['restaurant_product_id' => $restaurantProductB->id, 'sort_order' => 10]);

        $response = $this->actingAs($owner, 'web')
            ->getJson("/api/v1/categories/{$category->id}/products")
            ->assertOk();

        $items = collect($response->json('data.category_products'));
        $this->assertSame(2, $items->count());
        $this->assertSame($restaurantProductB->id, $items->first()['restaurant_product_id']);
        $this->assertSame($restaurantProductA->id, $items->last()['restaurant_product_id']);
        $this->assertSame($productB->id, $items->first()['restaurant_product']['product']['id']);
    }

    public function test_category_products_of_another_organization_returns_not_found(): void
    {
        [, $ownerA] = $this->createTenant();
        [$organizationB, , $restaurantB] = $this->createTenant();
        $menuB = $this->createMenu($restaurantB);
        $categoryB = $this->createCategory($menuB);

        $this->actingAs($ownerA, 'web')
            ->getJson("/api/v1/categories/{$categoryB->id}/products")
            ->assertNotFound();
    }

    public function test_user_without_manage_menu_permission_cannot_list_category_products(): void
    {
        [$organization, , $restaurant] = $this->createTenant();
        $menu = $this->createMenu($restaurant);
        $category = $this->createCategory($menu);
        $kitchen = $this->createStaff($organization, $restaurant, 'kitchen', 'K-1');

        $this->actingAs($kitchen, 'web')
            ->getJson("/api/v1/categories/{$category->id}/products")
            ->assertForbidden();
    }

    public function test_owner_can_remove_a_product_from_a_category(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $menu = $this->createMenu($restaurant);
        $category = $this->createCategory($menu);
        $product = $this->createProduct($organization);
        $restaurantProduct = $this->createRestaurantProduct($restaurant, $product);
        $category->categoryProducts()->create(['restaurant_product_id' => $restaurantProduct->id]);

        $this->actingAs($owner, 'web')
            ->deleteJson("/api/v1/categories/{$category->id}/products/{$restaurantProduct->id}")
            ->assertOk();

        $this->assertDatabaseMissing('category_products', [
            'category_id' => $category->id,
            'restaurant_product_id' => $restaurantProduct->id,
        ]);
        // The underlying association and product remain untouched.
        $this->assertDatabaseHas('restaurant_products', ['id' => $restaurantProduct->id]);
        $this->assertDatabaseHas('products', ['id' => $product->id]);
    }

    public function test_removing_a_product_from_a_category_it_is_not_in_returns_not_found(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $menu = $this->createMenu($restaurant);
        $category = $this->createCategory($menu);
        $product = $this->createProduct($organization);
        $restaurantProduct = $this->createRestaurantProduct($restaurant, $product);

        $this->actingAs($owner, 'web')
            ->deleteJson("/api/v1/categories/{$category->id}/products/{$restaurantProduct->id}")
            ->assertNotFound();
    }

    public function test_user_without_manage_menu_permission_cannot_remove_a_product_from_a_category(): void
    {
        [$organization, , $restaurant] = $this->createTenant();
        $menu = $this->createMenu($restaurant);
        $category = $this->createCategory($menu);
        $product = $this->createProduct($organization);
        $restaurantProduct = $this->createRestaurantProduct($restaurant, $product);
        $category->categoryProducts()->create(['restaurant_product_id' => $restaurantProduct->id]);
        $kitchen = $this->createStaff($organization, $restaurant, 'kitchen', 'K-1');

        $this->actingAs($kitchen, 'web')
            ->deleteJson("/api/v1/categories/{$category->id}/products/{$restaurantProduct->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('category_products', [
            'category_id' => $category->id,
            'restaurant_product_id' => $restaurantProduct->id,
        ]);
    }
}
