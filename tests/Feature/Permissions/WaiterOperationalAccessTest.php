<?php

namespace Tests\Feature\Permissions;

use App\Models\CategoryProduct;
use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithOrders;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Passo 3.2 fix: revalidation with a real waiter (create_orders,
 * approve_customer_orders, serve_orders, handle_table_requests,
 * record_payments, manage_tables, close_bill — no manage_menu, no
 * manage_products) found the frontend's "Nuevo pedido" screen reusing the
 * SAME catalog composables as the admin Carta screen (useRestaurantCategories,
 * useCategoryProducts, useProductModifierGroups — confirmed by reading
 * restaurants-web directly), which used to require manage_menu/
 * manage_products to even READ. This class proves the fix: a waiter can now
 * read live operations + everything needed to build a manual order, but
 * still cannot create/update/delete anything on the Carta, and still
 * cannot read a sibling restaurant's catalog. See CategoryPolicy,
 * ModifierGroupPolicy, ModifierOptionPolicy and RolePermissionSeeder.
 */
class WaiterOperationalAccessTest extends TestCase
{
    use InteractsWithOrders, InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    // --- Positive: everything the "Nuevo pedido" screen needs -------------

    public function test_waiter_can_view_operations_live(): void
    {
        [$organization, , $restaurant] = $this->createTenant();
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');

        $this->actingAs($waiter, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/operations/live")
            ->assertOk();
    }

    public function test_waiter_can_list_and_view_categories(): void
    {
        [$organization, , $restaurant] = $this->createTenant();
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');
        $menu = $this->createMenu($restaurant);
        $category = $this->createCategory($menu);

        $this->actingAs($waiter, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/categories")
            ->assertOk()
            ->assertJsonCount(1, 'data.categories');

        $this->actingAs($waiter, 'web')
            ->getJson("/api/v1/categories/{$category->id}")
            ->assertOk()
            ->assertJsonPath('data.category.id', $category->id);
    }

    public function test_waiter_can_list_the_products_of_a_category(): void
    {
        [$organization, , $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');
        $menu = $this->createMenu($restaurant);
        $category = $this->createCategory($menu);
        CategoryProduct::query()->create(['category_id' => $category->id, 'restaurant_product_id' => $rp->id, 'sort_order' => 0]);

        $this->actingAs($waiter, 'web')
            ->getJson("/api/v1/categories/{$category->id}/products")
            ->assertOk()
            ->assertJsonCount(1, 'data.category_products');
    }

    public function test_waiter_can_list_and_view_modifier_groups_and_options(): void
    {
        [$organization, , $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');
        $group = $this->createModifierGroup($rp, 'Punto de la carne', maxSelect: 1, required: true);
        $option = $this->createModifierOption($group, 'Al punto');

        $this->actingAs($waiter, 'web')
            ->getJson("/api/v1/restaurant-products/{$rp->id}/modifier-groups")
            ->assertOk()
            ->assertJsonCount(1, 'data.modifier_groups');

        $this->actingAs($waiter, 'web')
            ->getJson("/api/v1/modifier-groups/{$group->id}")
            ->assertOk()
            ->assertJsonPath('data.modifier_group.id', $group->id);

        $this->actingAs($waiter, 'web')
            ->getJson("/api/v1/modifier-groups/{$group->id}/options")
            ->assertOk()
            ->assertJsonCount(1, 'data.modifier_options');

        $this->actingAs($waiter, 'web')
            ->getJson("/api/v1/modifier-options/{$option->id}")
            ->assertOk()
            ->assertJsonPath('data.modifier_option.id', $option->id);
    }

    public function test_waiter_can_create_a_staff_order(): void
    {
        [$organization, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);

        $this->actingAs($waiter, 'web')
            ->postJson("/api/v1/tables/{$table->id}/orders", [
                'items' => [['restaurant_product_id' => $rp->id, 'quantity' => 1]],
            ])
            ->assertCreated();
    }

    // --- Negative: still no administrative access to the Carta ------------

    public function test_waiter_cannot_create_update_or_delete_categories(): void
    {
        [$organization, , $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');
        $menu = $this->createMenu($restaurant);
        $category = $this->createCategory($menu);

        $this->actingAs($waiter, 'web')
            ->postJson("/api/v1/restaurants/{$restaurant->id}/categories", [
                'slug' => 'new-category',
                'translations' => [['locale' => 'en', 'name' => 'New category']],
            ])
            ->assertForbidden();

        $this->actingAs($waiter, 'web')
            ->patchJson("/api/v1/categories/{$category->id}", ['sort_order' => 9])
            ->assertForbidden();

        // Attaching (not yet linked) still 403s — asserted BEFORE the
        // direct-model link below so the request actually reaches the
        // Policy instead of failing request validation on a duplicate.
        $this->actingAs($waiter, 'web')
            ->postJson("/api/v1/categories/{$category->id}/products", ['restaurant_product_id' => $rp->id])
            ->assertForbidden();

        CategoryProduct::query()->create(['category_id' => $category->id, 'restaurant_product_id' => $rp->id, 'sort_order' => 0]);

        $this->actingAs($waiter, 'web')
            ->deleteJson("/api/v1/categories/{$category->id}/products/{$rp->id}")
            ->assertForbidden();
    }

    public function test_waiter_cannot_mutate_products_or_modifiers(): void
    {
        [$organization, , $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');
        $group = $this->createModifierGroup($rp, 'Extras', maxSelect: 3);
        $option = $this->createModifierOption($group, 'Queso');

        $this->actingAs($waiter, 'web')
            ->postJson('/api/v1/products', [
                'internal_name' => 'New product',
                'translations' => [['locale' => 'en', 'name' => 'New product', 'description' => 'A new product.']],
                'allergens' => [],
            ])
            ->assertForbidden();

        $this->actingAs($waiter, 'web')
            ->patchJson("/api/v1/restaurant-products/{$rp->id}", ['price' => 99, 'available' => false])
            ->assertForbidden();

        $this->actingAs($waiter, 'web')
            ->postJson("/api/v1/restaurant-products/{$rp->id}/modifier-groups", [
                'internal_name' => 'Sneaky group',
                'max_select' => 1,
                'translations' => [['locale' => 'en', 'name' => 'Sneaky group']],
            ])
            ->assertForbidden();

        $this->actingAs($waiter, 'web')
            ->patchJson("/api/v1/modifier-groups/{$group->id}", ['max_select' => 5])
            ->assertForbidden();

        $this->actingAs($waiter, 'web')
            ->postJson("/api/v1/modifier-groups/{$group->id}/options", [
                'internal_name' => 'Sneaky option',
                'translations' => [['locale' => 'en', 'name' => 'Sneaky option']],
            ])
            ->assertForbidden();

        $this->actingAs($waiter, 'web')
            ->patchJson("/api/v1/modifier-options/{$option->id}", ['price_delta' => 100])
            ->assertForbidden();
    }

    // --- Tenant isolation ---------------------------------------------------

    public function test_waiter_scoped_to_one_restaurant_cannot_read_a_sibling_restaurants_catalog(): void
    {
        [$organization, $owner, $restaurantA] = $this->createTenant();
        $restaurantB = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $waiter = $this->createStaff($organization, $restaurantA, 'waiter', 'W-1');

        $productB = $this->createProduct($organization);
        $rpB = $this->createRestaurantProduct($restaurantB, $productB);
        $menuB = $this->createMenu($restaurantB);
        $categoryB = $this->createCategory($menuB);
        CategoryProduct::query()->create(['category_id' => $categoryB->id, 'restaurant_product_id' => $rpB->id, 'sort_order' => 0]);
        $groupB = $this->createModifierGroup($rpB, 'Extras', maxSelect: 1);

        $this->actingAs($waiter, 'web')
            ->getJson("/api/v1/restaurants/{$restaurantB->id}/categories")
            ->assertForbidden();

        $this->actingAs($waiter, 'web')
            ->getJson("/api/v1/categories/{$categoryB->id}")
            ->assertForbidden();

        $this->actingAs($waiter, 'web')
            ->getJson("/api/v1/categories/{$categoryB->id}/products")
            ->assertForbidden();

        $this->actingAs($waiter, 'web')
            ->getJson("/api/v1/restaurant-products/{$rpB->id}/modifier-groups")
            ->assertForbidden();

        $this->actingAs($waiter, 'web')
            ->getJson("/api/v1/modifier-groups/{$groupB->id}")
            ->assertForbidden();

        // Restaurant A (their own restaurant) is unaffected.
        $this->actingAs($waiter, 'web')
            ->getJson("/api/v1/restaurants/{$restaurantA->id}/categories")
            ->assertOk();
    }

    // --- Other roles: owner/manager unaffected, kitchen/cashier untouched --

    public function test_owner_keeps_full_carta_access(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $this->createMenu($restaurant);

        $this->actingAs($owner, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/categories")
            ->assertOk();

        $this->actingAs($owner, 'web')
            ->postJson("/api/v1/restaurants/{$restaurant->id}/categories", [
                'slug' => 'cat-owner',
                'translations' => [['locale' => 'en', 'name' => 'Cat']],
            ])
            ->assertCreated();
    }

    public function test_manager_keeps_full_carta_access(): void
    {
        [$organization, , $restaurant] = $this->createTenant();
        $manager = $this->createStaff($organization, $restaurant, 'manager', 'M-1');
        $this->createMenu($restaurant);

        $this->actingAs($manager, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/categories")
            ->assertOk();

        $this->actingAs($manager, 'web')
            ->postJson("/api/v1/restaurants/{$restaurant->id}/categories", [
                'slug' => 'cat-manager',
                'translations' => [['locale' => 'en', 'name' => 'Cat']],
            ])
            ->assertCreated();
    }

    public function test_kitchen_does_not_gain_catalog_or_operations_access(): void
    {
        [$organization, , $restaurant] = $this->createTenantWithRestaurantProduct();
        $kitchen = $this->createStaff($organization, $restaurant, 'kitchen', 'K-1');
        $menu = $this->createMenu($restaurant);
        $category = $this->createCategory($menu);

        $this->actingAs($kitchen, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/operations/live")
            ->assertForbidden();

        $this->actingAs($kitchen, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/categories")
            ->assertForbidden();

        $this->actingAs($kitchen, 'web')
            ->getJson("/api/v1/categories/{$category->id}")
            ->assertForbidden();
    }

    public function test_cashier_does_not_gain_catalog_or_operations_access(): void
    {
        [$organization, , $restaurant] = $this->createTenantWithRestaurantProduct();
        $cashier = $this->createStaff($organization, $restaurant, 'cashier', 'C-1');
        $menu = $this->createMenu($restaurant);
        $category = $this->createCategory($menu);

        $this->actingAs($cashier, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/operations/live")
            ->assertForbidden();

        $this->actingAs($cashier, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/categories")
            ->assertForbidden();

        $this->actingAs($cashier, 'web')
            ->getJson("/api/v1/categories/{$category->id}")
            ->assertForbidden();
    }
}
