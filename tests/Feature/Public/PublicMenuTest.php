<?php

namespace Tests\Feature\Public;

use App\Models\Category;
use App\Models\CategoryProduct;
use App\Models\Organization;
use App\Models\Restaurant;
use App\Models\Table;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class PublicMenuTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
    }

    // --- Availability -------------------------------------------------

    public function test_content_type_is_json(): void
    {
        [, , $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $this->createMenu($restaurant);

        $this->getJson("/api/v1/public/tables/{$table->public_token}/menu")
            ->assertHeader('Content-Type', 'application/json');
    }

    public function test_unknown_token_returns_public_table_not_found(): void
    {
        $this->getJson('/api/v1/public/tables/unknown/menu')
            ->assertStatus(404)
            ->assertJson(['error' => ['code' => 'PUBLIC_TABLE_NOT_FOUND', 'message' => 'Table not found.']]);
    }

    public function test_missing_menu_returns_menu_not_available(): void
    {
        [, , $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);

        $this->getJson("/api/v1/public/tables/{$table->public_token}/menu")
            ->assertStatus(404)
            ->assertJson(['error' => ['code' => 'MENU_NOT_AVAILABLE', 'message' => 'Menu is not available.']]);
    }

    public function test_inactive_menu_returns_menu_not_available(): void
    {
        [, , $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $menu = $this->createMenu($restaurant);
        $menu->update(['status' => 'inactive']);

        $this->getJson("/api/v1/public/tables/{$table->public_token}/menu")
            ->assertStatus(404)
            ->assertJson(['error' => ['code' => 'MENU_NOT_AVAILABLE', 'message' => 'Menu is not available.']]);
    }

    public function test_active_menu_with_no_categories_is_a_valid_empty_menu(): void
    {
        [, , $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $menu = $this->createMenu($restaurant);

        $response = $this->withHeader('Accept-Language', '')
            ->getJson("/api/v1/public/tables/{$table->public_token}/menu")
            ->assertOk();

        $response->assertJson([
            'data' => [
                'locale' => 'es',
                'menu' => ['id' => $menu->id, 'categories' => []],
            ],
        ]);
    }

    // --- Top-level shape ------------------------------------------------

    public function test_success_response_has_stable_top_level_keys(): void
    {
        [, , $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $this->createMenu($restaurant);

        $response = $this->getJson("/api/v1/public/tables/{$table->public_token}/menu")->assertOk();

        $this->assertEqualsCanonicalizing(
            ['restaurant', 'table', 'session', 'locale', 'menu'],
            array_keys($response->json('data'))
        );
    }

    // --- Category ---------------------------------------------------

    public function test_inactive_category_does_not_appear(): void
    {
        [, , $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $menu = $this->createMenu($restaurant);
        $category = $this->createCategory($menu, 'inactive-cat', [['locale' => 'es', 'name' => 'Oculto']]);
        $category->update(['status' => 'inactive']);
        $this->publishProductInCategory($restaurant, $category);

        $response = $this->getJson("/api/v1/public/tables/{$table->public_token}/menu")->assertOk();

        $this->assertSame([], $response->json('data.menu.categories'));
    }

    public function test_category_without_translation_does_not_appear(): void
    {
        [, , $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $menu = $this->createMenu($restaurant);
        $category = $menu->categories()->create(['slug' => 'no-name', 'sort_order' => 0, 'status' => 'active']);
        $this->publishProductInCategory($restaurant, $category);

        $response = $this->getJson("/api/v1/public/tables/{$table->public_token}/menu")->assertOk();

        $this->assertSame([], $response->json('data.menu.categories'));
    }

    public function test_category_empty_after_product_filtering_does_not_appear(): void
    {
        [, , $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $menu = $this->createMenu($restaurant);
        // Category is valid (active, has a translation) but has no products at all.
        $this->createCategory($menu, 'empty-cat', [['locale' => 'es', 'name' => 'Vacío']]);

        $response = $this->getJson("/api/v1/public/tables/{$table->public_token}/menu")->assertOk();

        $this->assertSame([], $response->json('data.menu.categories'));
    }

    public function test_categories_are_ordered_by_sort_order_then_id(): void
    {
        [, , $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $menu = $this->createMenu($restaurant);

        $second = $this->createCategory($menu, 'second', [['locale' => 'es', 'name' => 'Segundo']]);
        $second->update(['sort_order' => 5]);
        $this->publishProductInCategory($restaurant, $second);

        $first = $this->createCategory($menu, 'first', [['locale' => 'es', 'name' => 'Primero']]);
        $first->update(['sort_order' => 1]);
        $this->publishProductInCategory($restaurant, $first);

        $response = $this->getJson("/api/v1/public/tables/{$table->public_token}/menu")->assertOk();

        $this->assertSame(
            ['first', 'second'],
            collect($response->json('data.menu.categories'))->pluck('slug')->all()
        );
    }

    public function test_category_contract_fields(): void
    {
        [, , $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $menu = $this->createMenu($restaurant);
        $category = $this->createCategory($menu, 'burgers', [['locale' => 'es', 'name' => 'Hamburguesas']]);
        $this->publishProductInCategory($restaurant, $category);

        $response = $this->getJson("/api/v1/public/tables/{$table->public_token}/menu")->assertOk();

        $categoryJson = $response->json('data.menu.categories.0');
        $this->assertEqualsCanonicalizing(['id', 'slug', 'name', 'description', 'products'], array_keys($categoryJson));
        $this->assertSame('burgers', $categoryJson['slug']);
        $this->assertSame('Hamburguesas', $categoryJson['name']);
        $this->assertNull($categoryJson['description']);
    }

    // --- Product ------------------------------------------------------

    public function test_active_product_with_available_restaurant_product_appears(): void
    {
        [, , $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $menu = $this->createMenu($restaurant);
        $category = $this->createCategory($menu, 'cat', [['locale' => 'es', 'name' => 'Categoria']]);
        $this->publishProductInCategory($restaurant, $category);

        $response = $this->getJson("/api/v1/public/tables/{$table->public_token}/menu")->assertOk();

        $this->assertCount(1, $response->json('data.menu.categories.0.products'));
    }

    public function test_inactive_product_does_not_appear(): void
    {
        [, , $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $menu = $this->createMenu($restaurant);
        $category = $this->createCategory($menu, 'cat', [['locale' => 'es', 'name' => 'Categoria']]);
        $product = $this->createProduct($restaurant->organization, null, [['locale' => 'es', 'name' => 'Producto']]);
        $product->update(['status' => 'inactive']);
        $rp = $this->createRestaurantProduct($restaurant, $product);
        CategoryProduct::query()->create(['category_id' => $category->id, 'restaurant_product_id' => $rp->id, 'sort_order' => 0]);

        $response = $this->getJson("/api/v1/public/tables/{$table->public_token}/menu")->assertOk();

        $this->assertSame([], $response->json('data.menu.categories'));
    }

    public function test_unavailable_restaurant_product_does_not_appear(): void
    {
        [, , $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $menu = $this->createMenu($restaurant);
        $category = $this->createCategory($menu, 'cat', [['locale' => 'es', 'name' => 'Categoria']]);
        $product = $this->createProduct($restaurant->organization, null, [['locale' => 'es', 'name' => 'Producto']]);
        $rp = $this->createRestaurantProduct($restaurant, $product, 10, false);
        CategoryProduct::query()->create(['category_id' => $category->id, 'restaurant_product_id' => $rp->id, 'sort_order' => 0]);

        $response = $this->getJson("/api/v1/public/tables/{$table->public_token}/menu")->assertOk();

        $this->assertSame([], $response->json('data.menu.categories'));
    }

    public function test_product_without_translation_does_not_appear(): void
    {
        [, , $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $menu = $this->createMenu($restaurant);
        $category = $this->createCategory($menu, 'cat', [['locale' => 'es', 'name' => 'Categoria']]);
        $product = $restaurant->organization->products()->create(['internal_name' => 'No Name', 'status' => 'active']);
        $rp = $this->createRestaurantProduct($restaurant, $product);
        CategoryProduct::query()->create(['category_id' => $category->id, 'restaurant_product_id' => $rp->id, 'sort_order' => 0]);

        $response = $this->getJson("/api/v1/public/tables/{$table->public_token}/menu")->assertOk();

        $this->assertSame([], $response->json('data.menu.categories'));
    }

    public function test_product_price_is_a_two_decimal_string(): void
    {
        [, , $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $menu = $this->createMenu($restaurant);
        $category = $this->createCategory($menu, 'cat', [['locale' => 'es', 'name' => 'Categoria']]);
        $product = $this->createProduct($restaurant->organization, null, [['locale' => 'es', 'name' => 'Producto']]);
        $rp = $this->createRestaurantProduct($restaurant, $product, 12.9);
        CategoryProduct::query()->create(['category_id' => $category->id, 'restaurant_product_id' => $rp->id, 'sort_order' => 0]);

        $response = $this->getJson("/api/v1/public/tables/{$table->public_token}/menu")->assertOk();

        $productJson = $response->json('data.menu.categories.0.products.0');
        $this->assertSame('12.90', $productJson['price']);
        $this->assertIsString($productJson['price']);
    }

    public function test_product_contract_fields(): void
    {
        [, , $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $menu = $this->createMenu($restaurant);
        $category = $this->createCategory($menu, 'cat', [['locale' => 'es', 'name' => 'Categoria']]);
        $product = $this->createProduct($restaurant->organization, 'Internal Burger Name', [
            ['locale' => 'es', 'name' => 'Hamburguesa Clásica', 'description' => 'Carne, queso y salsa'],
        ]);
        $rp = $this->createRestaurantProduct($restaurant, $product, 12.9);
        CategoryProduct::query()->create(['category_id' => $category->id, 'restaurant_product_id' => $rp->id, 'sort_order' => 0]);

        $response = $this->getJson("/api/v1/public/tables/{$table->public_token}/menu")->assertOk();
        $productJson = $response->json('data.menu.categories.0.products.0');

        $this->assertEqualsCanonicalizing(
            ['restaurant_product_id', 'product_id', 'name', 'description', 'price', 'modifier_groups'],
            array_keys($productJson)
        );
        $this->assertSame($rp->id, $productJson['restaurant_product_id']);
        $this->assertSame($product->id, $productJson['product_id']);
        $this->assertSame('Hamburguesa Clásica', $productJson['name']);
        $this->assertSame('Carne, queso y salsa', $productJson['description']);
        $this->assertSame([], $productJson['modifier_groups']);

        $productJsonEncoded = json_encode($productJson);
        $this->assertStringNotContainsString('internal_name', $productJsonEncoded);
        $this->assertStringNotContainsString('Internal Burger Name', $productJsonEncoded);
        $this->assertStringNotContainsString('"status"', $productJsonEncoded);
        $this->assertStringNotContainsString('"available"', $productJsonEncoded);
    }

    public function test_products_are_ordered_by_category_product_sort_order_then_id(): void
    {
        [, , $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $menu = $this->createMenu($restaurant);
        $category = $this->createCategory($menu, 'cat', [['locale' => 'es', 'name' => 'Categoria']]);

        $productB = $this->createProduct($restaurant->organization, null, [['locale' => 'es', 'name' => 'B']]);
        $rpB = $this->createRestaurantProduct($restaurant, $productB);
        CategoryProduct::query()->create(['category_id' => $category->id, 'restaurant_product_id' => $rpB->id, 'sort_order' => 10]);

        $productA = $this->createProduct($restaurant->organization, null, [['locale' => 'es', 'name' => 'A']]);
        $rpA = $this->createRestaurantProduct($restaurant, $productA);
        CategoryProduct::query()->create(['category_id' => $category->id, 'restaurant_product_id' => $rpA->id, 'sort_order' => 1]);

        $response = $this->getJson("/api/v1/public/tables/{$table->public_token}/menu")->assertOk();

        $this->assertSame(
            ['A', 'B'],
            collect($response->json('data.menu.categories.0.products'))->pluck('name')->all()
        );
    }

    public function test_same_restaurant_product_can_appear_in_multiple_categories(): void
    {
        [, , $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $menu = $this->createMenu($restaurant);
        $categoryA = $this->createCategory($menu, 'cat-a', [['locale' => 'es', 'name' => 'A']]);
        $categoryB = $this->createCategory($menu, 'cat-b', [['locale' => 'es', 'name' => 'B']]);

        $product = $this->createProduct($restaurant->organization, null, [['locale' => 'es', 'name' => 'Combo']]);
        $rp = $this->createRestaurantProduct($restaurant, $product);
        CategoryProduct::query()->create(['category_id' => $categoryA->id, 'restaurant_product_id' => $rp->id, 'sort_order' => 0]);
        CategoryProduct::query()->create(['category_id' => $categoryB->id, 'restaurant_product_id' => $rp->id, 'sort_order' => 0]);

        $response = $this->getJson("/api/v1/public/tables/{$table->public_token}/menu")->assertOk();

        $this->assertCount(2, $response->json('data.menu.categories'));
        $this->assertCount(1, $response->json('data.menu.categories.0.products'));
        $this->assertCount(1, $response->json('data.menu.categories.1.products'));
    }

    // --- ModifierGroup --------------------------------------------------

    public function test_active_modifier_group_with_options_appears(): void
    {
        [, , $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        [$table] = $this->buildTableAndCategoryWithProduct($restaurant, $rp);
        $group = $this->createModifierGroup($rp, null, 0, 4, false, [['locale' => 'es', 'name' => 'Extras']]);
        $this->createModifierOption($group, null, 1.5, [['locale' => 'es', 'name' => 'Bacon']]);

        $response = $this->getJson("/api/v1/public/tables/{$table->public_token}/menu")->assertOk();

        $this->assertCount(1, $response->json('data.menu.categories.0.products.0.modifier_groups'));
    }

    public function test_inactive_modifier_group_does_not_appear(): void
    {
        [, , $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        [$table] = $this->buildTableAndCategoryWithProduct($restaurant, $rp);
        $group = $this->createModifierGroup($rp, null, 0, 4, false, [['locale' => 'es', 'name' => 'Extras']]);
        $this->createModifierOption($group, null, 1.5, [['locale' => 'es', 'name' => 'Bacon']]);
        $group->update(['status' => 'inactive']);

        $response = $this->getJson("/api/v1/public/tables/{$table->public_token}/menu")->assertOk();

        $this->assertSame([], $response->json('data.menu.categories.0.products.0.modifier_groups'));
    }

    public function test_modifier_group_without_translation_does_not_appear(): void
    {
        [, , $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        [$table] = $this->buildTableAndCategoryWithProduct($restaurant, $rp);
        $group = $rp->modifierGroups()->create([
            'internal_name' => 'No name', 'min_select' => 0, 'max_select' => 1, 'required' => false, 'sort_order' => 0, 'status' => 'active',
        ]);
        $group->options()->create(['internal_name' => 'Opt', 'price_delta' => 0, 'available' => true, 'sort_order' => 0, 'status' => 'active']);

        $response = $this->getJson("/api/v1/public/tables/{$table->public_token}/menu")->assertOk();

        $this->assertSame([], $response->json('data.menu.categories.0.products.0.modifier_groups'));
    }

    public function test_modifier_group_without_any_public_option_does_not_appear(): void
    {
        [, , $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        [$table] = $this->buildTableAndCategoryWithProduct($restaurant, $rp);
        $group = $this->createModifierGroup($rp, null, 1, 1, true, [['locale' => 'es', 'name' => 'Obligatorio']]);
        $option = $this->createModifierOption($group, null, 0, [['locale' => 'es', 'name' => 'Unica']]);
        $option->update(['available' => false]);

        $response = $this->getJson("/api/v1/public/tables/{$table->public_token}/menu")->assertOk();

        $this->assertSame([], $response->json('data.menu.categories.0.products.0.modifier_groups'));
    }

    public function test_modifier_groups_are_ordered_by_sort_order_then_id(): void
    {
        [, , $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        [$table] = $this->buildTableAndCategoryWithProduct($restaurant, $rp);

        $groupB = $this->createModifierGroup($rp, null, 0, 1, false, [['locale' => 'es', 'name' => 'B']]);
        $groupB->update(['sort_order' => 5]);
        $this->createModifierOption($groupB, null, 0, [['locale' => 'es', 'name' => 'OptB']]);

        $groupA = $this->createModifierGroup($rp, null, 0, 1, false, [['locale' => 'es', 'name' => 'A']]);
        $groupA->update(['sort_order' => 1]);
        $this->createModifierOption($groupA, null, 0, [['locale' => 'es', 'name' => 'OptA']]);

        $response = $this->getJson("/api/v1/public/tables/{$table->public_token}/menu")->assertOk();

        $this->assertSame(
            ['A', 'B'],
            collect($response->json('data.menu.categories.0.products.0.modifier_groups'))->pluck('name')->all()
        );
    }

    public function test_modifier_group_contract_fields(): void
    {
        [, , $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        [$table] = $this->buildTableAndCategoryWithProduct($restaurant, $rp);
        $group = $this->createModifierGroup($rp, 'Internal Extras', 1, 4, true, [['locale' => 'es', 'name' => 'Extras']]);
        $this->createModifierOption($group, null, 0, [['locale' => 'es', 'name' => 'Queso']]);

        $response = $this->getJson("/api/v1/public/tables/{$table->public_token}/menu")->assertOk();
        $groupJson = $response->json('data.menu.categories.0.products.0.modifier_groups.0');

        $this->assertEqualsCanonicalizing(
            ['id', 'name', 'description', 'required', 'min_select', 'max_select', 'options'],
            array_keys($groupJson)
        );
        $this->assertSame($group->id, $groupJson['id']);
        $this->assertSame('Extras', $groupJson['name']);
        $this->assertTrue($groupJson['required']);
        $this->assertSame(1, $groupJson['min_select']);
        $this->assertSame(4, $groupJson['max_select']);

        $json = json_encode($response->json());
        $this->assertStringNotContainsString('Internal Extras', $json);
    }

    // --- ModifierOption -------------------------------------------------

    public function test_inactive_modifier_option_does_not_appear_but_group_with_other_options_does(): void
    {
        [, , $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        [$table] = $this->buildTableAndCategoryWithProduct($restaurant, $rp);
        $group = $this->createModifierGroup($rp, null, 0, 2, false, [['locale' => 'es', 'name' => 'Extras']]);
        $visible = $this->createModifierOption($group, null, 0, [['locale' => 'es', 'name' => 'Visible']]);
        $hidden = $this->createModifierOption($group, null, 0, [['locale' => 'es', 'name' => 'Oculto']]);
        $hidden->update(['status' => 'inactive']);

        $response = $this->getJson("/api/v1/public/tables/{$table->public_token}/menu")->assertOk();
        $options = $response->json('data.menu.categories.0.products.0.modifier_groups.0.options');

        $this->assertCount(1, $options);
        $this->assertSame('Visible', $options[0]['name']);
    }

    public function test_unavailable_modifier_option_does_not_appear(): void
    {
        [, , $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        [$table] = $this->buildTableAndCategoryWithProduct($restaurant, $rp);
        $group = $this->createModifierGroup($rp, null, 0, 2, false, [['locale' => 'es', 'name' => 'Extras']]);
        $visible = $this->createModifierOption($group, null, 0, [['locale' => 'es', 'name' => 'Visible']]);
        $hidden = $this->createModifierOption($group, null, 0, [['locale' => 'es', 'name' => 'Oculto']]);
        $hidden->update(['available' => false]);

        $response = $this->getJson("/api/v1/public/tables/{$table->public_token}/menu")->assertOk();
        $options = $response->json('data.menu.categories.0.products.0.modifier_groups.0.options');

        $this->assertCount(1, $options);
        $this->assertSame('Visible', $options[0]['name']);
    }

    public function test_modifier_option_without_translation_does_not_appear(): void
    {
        [, , $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        [$table] = $this->buildTableAndCategoryWithProduct($restaurant, $rp);
        $group = $this->createModifierGroup($rp, null, 0, 2, false, [['locale' => 'es', 'name' => 'Extras']]);
        $this->createModifierOption($group, null, 0, [['locale' => 'es', 'name' => 'Visible']]);
        $group->options()->create(['internal_name' => 'No Name', 'price_delta' => 0, 'available' => true, 'sort_order' => 1, 'status' => 'active']);

        $response = $this->getJson("/api/v1/public/tables/{$table->public_token}/menu")->assertOk();
        $options = $response->json('data.menu.categories.0.products.0.modifier_groups.0.options');

        $this->assertCount(1, $options);
    }

    public function test_modifier_option_price_delta_is_a_two_decimal_string(): void
    {
        [, , $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        [$table] = $this->buildTableAndCategoryWithProduct($restaurant, $rp);
        $group = $this->createModifierGroup($rp, null, 0, 1, false, [['locale' => 'es', 'name' => 'Extras']]);
        $this->createModifierOption($group, null, 1.5, [['locale' => 'es', 'name' => 'Bacon']]);
        $this->createModifierOption($group, null, 0, [['locale' => 'es', 'name' => 'Gratis']]);

        $response = $this->getJson("/api/v1/public/tables/{$table->public_token}/menu")->assertOk();
        $options = collect($response->json('data.menu.categories.0.products.0.modifier_groups.0.options'));

        $this->assertSame('1.50', $options->firstWhere('name', 'Bacon')['price_delta']);
        $this->assertSame('0.00', $options->firstWhere('name', 'Gratis')['price_delta']);
        $this->assertIsString($options->first()['price_delta']);
    }

    public function test_modifier_options_are_ordered_by_sort_order_then_id(): void
    {
        [, , $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        [$table] = $this->buildTableAndCategoryWithProduct($restaurant, $rp);
        $group = $this->createModifierGroup($rp, null, 0, 2, false, [['locale' => 'es', 'name' => 'Extras']]);

        $optionB = $this->createModifierOption($group, null, 0, [['locale' => 'es', 'name' => 'B']]);
        $optionB->update(['sort_order' => 5]);
        $optionA = $this->createModifierOption($group, null, 0, [['locale' => 'es', 'name' => 'A']]);
        $optionA->update(['sort_order' => 1]);

        $response = $this->getJson("/api/v1/public/tables/{$table->public_token}/menu")->assertOk();

        $this->assertSame(
            ['A', 'B'],
            collect($response->json('data.menu.categories.0.products.0.modifier_groups.0.options'))->pluck('name')->all()
        );
    }

    public function test_modifier_option_contract_fields(): void
    {
        [, , $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        [$table] = $this->buildTableAndCategoryWithProduct($restaurant, $rp);
        $group = $this->createModifierGroup($rp, null, 0, 1, false, [['locale' => 'es', 'name' => 'Extras']]);
        $this->createModifierOption($group, 'Internal Bacon', 1.5, [['locale' => 'es', 'name' => 'Bacon']]);

        $response = $this->getJson("/api/v1/public/tables/{$table->public_token}/menu")->assertOk();
        $optionJson = $response->json('data.menu.categories.0.products.0.modifier_groups.0.options.0');

        $this->assertEqualsCanonicalizing(['id', 'name', 'description', 'price_delta'], array_keys($optionJson));

        $json = json_encode($response->json());
        $this->assertStringNotContainsString('Internal Bacon', $json);
        $this->assertStringNotContainsString('modifier_group_id', $json);
    }

    // --- Locale -----------------------------------------------------

    public function test_locale_query_selects_the_matching_translation(): void
    {
        [, , $restaurant] = $this->createTenant();
        // enabled_locales gates an EXPLICIT ?locale= selection (Bloco 18);
        // widened here since this test deliberately exercises arbitrary
        // language codes to prove the generic fallback mechanics, not the
        // Spain-specific allowlist (see PublicLocaleTest for that).
        $restaurant->settings()->update(['enabled_locales' => ['es-ES', 'en-GB', 'es', 'en', 'pt']]);
        $table = $this->createTable($restaurant);
        $menu = $this->createMenu($restaurant);
        $category = $this->createCategory($menu, 'cat', [
            ['locale' => 'es', 'name' => 'Categoria ES'],
            ['locale' => 'en', 'name' => 'Category EN'],
            ['locale' => 'pt', 'name' => 'Categoria PT'],
        ]);
        $this->publishProductInCategory($restaurant, $category);

        foreach (['es' => 'Categoria ES', 'en' => 'Category EN', 'pt' => 'Categoria PT'] as $locale => $expected) {
            $response = $this->getJson("/api/v1/public/tables/{$table->public_token}/menu?locale={$locale}")->assertOk();
            $this->assertSame($locale, $response->json('data.locale'));
            $this->assertSame($expected, $response->json('data.menu.categories.0.name'));
        }
    }

    public function test_regional_locale_falls_back_to_base_language(): void
    {
        [, , $restaurant] = $this->createTenant();
        $restaurant->settings()->update(['enabled_locales' => ['es-ES', 'en-GB', 'pt-BR']]);
        $table = $this->createTable($restaurant);
        $menu = $this->createMenu($restaurant);
        $category = $this->createCategory($menu, 'cat', [['locale' => 'pt', 'name' => 'Categoria PT']]);
        $this->publishProductInCategory($restaurant, $category);

        $response = $this->getJson("/api/v1/public/tables/{$table->public_token}/menu?locale=pt-BR")->assertOk();

        $this->assertSame('pt-BR', $response->json('data.locale'));
        $this->assertSame('Categoria PT', $response->json('data.menu.categories.0.name'));
    }

    public function test_regional_variant_falls_back_to_base_when_exact_variant_missing(): void
    {
        [, , $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $menu = $this->createMenu($restaurant);
        $category = $this->createCategory($menu, 'cat', [['locale' => 'es', 'name' => 'Categoria ES']]);
        $this->publishProductInCategory($restaurant, $category);

        $response = $this->getJson("/api/v1/public/tables/{$table->public_token}/menu?locale=es-ES")->assertOk();

        $this->assertSame('es-ES', $response->json('data.locale'));
        $this->assertSame('Categoria ES', $response->json('data.menu.categories.0.name'));
    }

    public function test_fallback_chain_reaches_es_then_first_available(): void
    {
        [, , $restaurant] = $this->createTenant();
        $restaurant->settings()->update(['enabled_locales' => ['es-ES', 'en-GB', 'fr']]);
        $table = $this->createTable($restaurant);
        $menu = $this->createMenu($restaurant);

        // Stage 1: exact locale exists.
        $catExact = $this->createCategory($menu, 'exact', [['locale' => 'fr', 'name' => 'Exact FR']]);
        $this->publishProductInCategory($restaurant, $catExact);

        $response = $this->getJson("/api/v1/public/tables/{$table->public_token}/menu?locale=fr")->assertOk();
        $this->assertSame('Exact FR', $response->json('data.menu.categories.0.name'));
    }

    public function test_fallback_chain_falls_back_to_es_when_requested_locale_and_base_are_absent(): void
    {
        [, , $restaurant] = $this->createTenant();
        $restaurant->settings()->update(['enabled_locales' => ['es-ES', 'en-GB', 'de']]);
        $table = $this->createTable($restaurant);
        $menu = $this->createMenu($restaurant);
        $category = $this->createCategory($menu, 'cat', [
            ['locale' => 'es', 'name' => 'Solo ES'],
            ['locale' => 'en', 'name' => 'Only EN'],
        ]);
        $this->publishProductInCategory($restaurant, $category);

        // Requested "de" has no exact/base match: falls back to "es".
        $response = $this->getJson("/api/v1/public/tables/{$table->public_token}/menu?locale=de")->assertOk();

        $this->assertSame('Solo ES', $response->json('data.menu.categories.0.name'));
    }

    public function test_fallback_chain_falls_back_to_first_translation_when_no_es_exists(): void
    {
        [, , $restaurant] = $this->createTenant();
        $restaurant->settings()->update(['enabled_locales' => ['es-ES', 'en-GB', 'de']]);
        $table = $this->createTable($restaurant);
        $menu = $this->createMenu($restaurant);
        $category = $this->createCategory($menu, 'cat', [['locale' => 'en', 'name' => 'Only EN']]);
        $this->publishProductInCategory($restaurant, $category);

        $response = $this->getJson("/api/v1/public/tables/{$table->public_token}/menu?locale=de")->assertOk();

        $this->assertSame('Only EN', $response->json('data.menu.categories.0.name'));
    }

    public function test_accept_language_header_is_used_when_no_query_locale(): void
    {
        [, , $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $menu = $this->createMenu($restaurant);
        $category = $this->createCategory($menu, 'cat', [
            ['locale' => 'en', 'name' => 'English name'],
            ['locale' => 'es', 'name' => 'Nombre en español'],
        ]);
        $this->publishProductInCategory($restaurant, $category);

        $response = $this->withHeader('Accept-Language', 'en-US,en;q=0.9,es;q=0.8')
            ->getJson("/api/v1/public/tables/{$table->public_token}/menu")
            ->assertOk();

        $this->assertSame('en-US', $response->json('data.locale'));
        $this->assertSame('English name', $response->json('data.menu.categories.0.name'));
    }

    public function test_query_locale_wins_over_accept_language_header(): void
    {
        [, , $restaurant] = $this->createTenant();
        $restaurant->settings()->update(['enabled_locales' => ['es-ES', 'en-GB', 'pt']]);
        $table = $this->createTable($restaurant);
        $menu = $this->createMenu($restaurant);
        $category = $this->createCategory($menu, 'cat', [
            ['locale' => 'en', 'name' => 'English name'],
            ['locale' => 'pt', 'name' => 'Nome em português'],
        ]);
        $this->publishProductInCategory($restaurant, $category);

        $response = $this->withHeader('Accept-Language', 'en-US,en;q=0.9')
            ->getJson("/api/v1/public/tables/{$table->public_token}/menu?locale=pt")
            ->assertOk();

        $this->assertSame('pt', $response->json('data.locale'));
        $this->assertSame('Nome em português', $response->json('data.menu.categories.0.name'));
    }

    public function test_no_locale_and_no_header_defaults_to_es(): void
    {
        [, , $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $this->createMenu($restaurant);

        $response = $this->withHeader('Accept-Language', '')
            ->getJson("/api/v1/public/tables/{$table->public_token}/menu")
            ->assertOk();

        $this->assertSame('es', $response->json('data.locale'));
    }

    public function test_invalid_query_locale_returns_422(): void
    {
        [, , $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $this->createMenu($restaurant);

        $response = $this->getJson("/api/v1/public/tables/{$table->public_token}/menu?locale=".urlencode('../../etc'))
            ->assertStatus(422);

        $response->assertJson(['error' => ['code' => 'INVALID_LOCALE', 'message' => 'The locale format is invalid.']]);
    }

    public function test_locale_casing_is_normalized(): void
    {
        [, , $restaurant] = $this->createTenant();
        $restaurant->settings()->update(['enabled_locales' => ['es-ES', 'en-GB', 'pt-BR']]);
        $table = $this->createTable($restaurant);
        $this->createMenu($restaurant);

        $response = $this->getJson("/api/v1/public/tables/{$table->public_token}/menu?locale=pt-br")->assertOk();

        $this->assertSame('pt-BR', $response->json('data.locale'));
    }

    // --- Arrays never null ---------------------------------------------

    public function test_collections_are_arrays_not_null_when_empty(): void
    {
        [, , $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        [$table, $category] = $this->buildTableAndCategoryWithProduct($restaurant, $rp);

        $response = $this->getJson("/api/v1/public/tables/{$table->public_token}/menu")->assertOk();
        $productJson = $response->json('data.menu.categories.0.products.0');

        $this->assertIsArray($response->json('data.menu.categories'));
        $this->assertIsArray($response->json('data.menu.categories.0.products'));
        $this->assertIsArray($productJson['modifier_groups']);
        $this->assertSame([], $productJson['modifier_groups']);
    }

    // --- Restaurant independence ----------------------------------------

    public function test_restaurant_products_are_independent_across_restaurants(): void
    {
        $organization = Organization::factory()->create();
        $owner = User::factory()->create();
        $this->assignRole($owner, 'owner', $organization);

        $restaurantA = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $restaurantB = Restaurant::factory()->create(['organization_id' => $organization->id]);

        $tableA = $this->createTable($restaurantA);
        $tableB = $this->createTable($restaurantB);

        $menuA = $this->createMenu($restaurantA);
        $menuB = $this->createMenu($restaurantB);

        $categoryA = $this->createCategory($menuA, 'cat', [['locale' => 'es', 'name' => 'Categoria']]);
        $categoryB = $this->createCategory($menuB, 'cat', [['locale' => 'es', 'name' => 'Categoria']]);

        $product = $this->createProduct($organization, null, [['locale' => 'es', 'name' => 'Hamburguesa']]);

        $rpA = $this->createRestaurantProduct($restaurantA, $product, 12.9);
        $rpB = $this->createRestaurantProduct($restaurantB, $product, 14.9);

        CategoryProduct::query()->create(['category_id' => $categoryA->id, 'restaurant_product_id' => $rpA->id, 'sort_order' => 0]);
        CategoryProduct::query()->create(['category_id' => $categoryB->id, 'restaurant_product_id' => $rpB->id, 'sort_order' => 0]);

        $groupA = $this->createModifierGroup($rpA, null, 0, 1, false, [['locale' => 'es', 'name' => 'Extras A']]);
        $this->createModifierOption($groupA, null, 0, [['locale' => 'es', 'name' => 'Bacon']]);

        $groupB = $this->createModifierGroup($rpB, null, 0, 1, false, [['locale' => 'es', 'name' => 'Extras B']]);
        $this->createModifierOption($groupB, null, 0, [['locale' => 'es', 'name' => 'Ovo']]);

        $responseA = $this->getJson("/api/v1/public/tables/{$tableA->public_token}/menu")->assertOk();
        $responseB = $this->getJson("/api/v1/public/tables/{$tableB->public_token}/menu")->assertOk();

        $productA = $responseA->json('data.menu.categories.0.products.0');
        $productB = $responseB->json('data.menu.categories.0.products.0');

        $this->assertSame('12.90', $productA['price']);
        $this->assertSame('Bacon', $productA['modifier_groups'][0]['options'][0]['name']);

        $this->assertSame('14.90', $productB['price']);
        $this->assertSame('Ovo', $productB['modifier_groups'][0]['options'][0]['name']);

        $this->assertSame($restaurantA->id, $responseA->json('data.restaurant.id'));
        $this->assertSame($restaurantB->id, $responseB->json('data.restaurant.id'));
    }

    // --- Helpers ----------------------------------------------------

    /**
     * Create a category with one active/available/translated product under
     * it, ready to appear in the public menu.
     */
    private function publishProductInCategory($restaurant, $category): CategoryProduct
    {
        $product = $this->createProduct($restaurant->organization, null, [['locale' => 'es', 'name' => 'Producto']]);
        $rp = $this->createRestaurantProduct($restaurant, $product);

        return CategoryProduct::query()->create([
            'category_id' => $category->id,
            'restaurant_product_id' => $rp->id,
            'sort_order' => 0,
        ]);
    }

    /**
     * @return array{0: Table, 1: Category}
     */
    private function buildTableAndCategoryWithProduct($restaurant, $rp): array
    {
        $table = $this->createTable($restaurant);
        $menu = $this->createMenu($restaurant);
        $category = $this->createCategory($menu, 'cat', [['locale' => 'es', 'name' => 'Categoria']]);
        CategoryProduct::query()->create(['category_id' => $category->id, 'restaurant_product_id' => $rp->id, 'sort_order' => 0]);

        return [$table, $category];
    }
}
