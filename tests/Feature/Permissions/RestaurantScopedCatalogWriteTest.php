<?php

namespace Tests\Feature\Permissions;

use App\Models\CategoryProduct;
use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Hardening follow-up to the Passo 3.2 waiter fix: a pre-existing gap was
 * found where every Carta WRITE ability (Category/RestaurantProduct/
 * ModifierGroup/ModifierOption/Menu create+update) checked organization
 * membership + the permission slug only — never RestaurantScope. Since
 * User::hasPermission() is organization-wide (it has no notion of WHICH
 * restaurant a permission was granted for), a manager whose
 * restaurant_users/user_roles rows scope them to only Restaurant A of an
 * organization could still mutate the Carta of a sibling Restaurant B in
 * the SAME organization, simply by knowing its id.
 *
 * This class proves the fix (see AuthorizesRestaurantScopedCatalog and
 * every *Policy's canManage()): a restaurant-scoped manager can fully
 * manage Restaurant A's Carta, but every one of those same mutations
 * against Restaurant B (same organization) is now 403 — while an
 * organization-wide owner (RestaurantScope::accessibleRestaurantIds()
 * returns null for them) is completely unaffected and can manage both.
 */
class RestaurantScopedCatalogWriteTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    /**
     * @return array{0: \App\Models\Organization, 1: \App\Models\User, 2: \App\Models\User, 3: Restaurant, 4: Restaurant}
     */
    private function twoRestaurantsWithScopedManager(): array
    {
        [$organization, $owner, $restaurantA] = $this->createTenant();
        $restaurantB = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $manager = $this->createStaff($organization, $restaurantA, 'manager', 'M-1');

        return [$organization, $owner, $manager, $restaurantA, $restaurantB];
    }

    // --- Manager restricted to Restaurant A: full access there -------------

    public function test_manager_can_manage_restaurant_a_menu_and_categories(): void
    {
        [, , $manager, $restaurantA] = $this->twoRestaurantsWithScopedManager();

        $this->actingAs($manager, 'web')
            ->postJson("/api/v1/restaurants/{$restaurantA->id}/menu", ['name' => 'Carta A'])
            ->assertCreated();

        $this->actingAs($manager, 'web')
            ->getJson("/api/v1/restaurants/{$restaurantA->id}/categories")
            ->assertOk();

        $response = $this->actingAs($manager, 'web')
            ->postJson("/api/v1/restaurants/{$restaurantA->id}/categories", [
                'slug' => 'starters',
                'translations' => [['locale' => 'en', 'name' => 'Starters']],
            ])
            ->assertCreated();

        $categoryId = $response->json('data.category.id');

        $this->actingAs($manager, 'web')
            ->patchJson("/api/v1/categories/{$categoryId}", ['sort_order' => 3])
            ->assertOk();
    }

    public function test_manager_can_manage_restaurant_a_products_and_modifiers(): void
    {
        [$organization, , $manager, $restaurantA] = $this->twoRestaurantsWithScopedManager();
        $product = $this->createProduct($organization);

        $response = $this->actingAs($manager, 'web')
            ->postJson("/api/v1/restaurants/{$restaurantA->id}/products", [
                'product_id' => $product->id,
                'price' => 9.5,
            ])
            ->assertCreated();

        $restaurantProductId = $response->json('data.restaurant_product.id');

        $this->actingAs($manager, 'web')
            ->patchJson("/api/v1/restaurant-products/{$restaurantProductId}", ['price' => 12.0])
            ->assertOk();

        $groupResponse = $this->actingAs($manager, 'web')
            ->postJson("/api/v1/restaurant-products/{$restaurantProductId}/modifier-groups", [
                'internal_name' => 'Extras',
                'max_select' => 2,
                'translations' => [['locale' => 'en', 'name' => 'Extras']],
            ])
            ->assertCreated();

        $groupId = $groupResponse->json('data.modifier_group.id');

        $this->actingAs($manager, 'web')
            ->patchJson("/api/v1/modifier-groups/{$groupId}", ['max_select' => 3])
            ->assertOk();

        $optionResponse = $this->actingAs($manager, 'web')
            ->postJson("/api/v1/modifier-groups/{$groupId}/options", [
                'internal_name' => 'Cheese',
                'translations' => [['locale' => 'en', 'name' => 'Cheese']],
            ])
            ->assertCreated();

        $optionId = $optionResponse->json('data.modifier_option.id');

        $this->actingAs($manager, 'web')
            ->patchJson("/api/v1/modifier-options/{$optionId}", ['price_delta' => 1.5])
            ->assertOk();
    }

    // --- Same manager against sibling Restaurant B (same organization) -----

    public function test_manager_cannot_manage_restaurant_bs_menu_or_categories(): void
    {
        [, $owner, $manager, , $restaurantB] = $this->twoRestaurantsWithScopedManager();
        $menuB = $this->createMenu($restaurantB);
        $categoryB = $this->createCategory($menuB);

        $this->actingAs($manager, 'web')
            ->postJson("/api/v1/restaurants/{$restaurantB->id}/menu", ['name' => 'Carta B'])
            ->assertForbidden();

        $this->actingAs($manager, 'web')
            ->patchJson("/api/v1/restaurants/{$restaurantB->id}/menu", ['name' => 'Renamed'])
            ->assertForbidden();

        $this->actingAs($manager, 'web')
            ->postJson("/api/v1/restaurants/{$restaurantB->id}/categories", [
                'slug' => 'sneaky',
                'translations' => [['locale' => 'en', 'name' => 'Sneaky']],
            ])
            ->assertForbidden();

        $this->actingAs($manager, 'web')
            ->patchJson("/api/v1/categories/{$categoryB->id}", ['sort_order' => 9])
            ->assertForbidden();
    }

    public function test_manager_cannot_attach_or_detach_products_on_restaurant_bs_categories(): void
    {
        [$organization, , $manager, , $restaurantB] = $this->twoRestaurantsWithScopedManager();
        $productB = $this->createProduct($organization);
        $rpB = $this->createRestaurantProduct($restaurantB, $productB);
        $menuB = $this->createMenu($restaurantB);
        $categoryB = $this->createCategory($menuB);

        $this->actingAs($manager, 'web')
            ->postJson("/api/v1/categories/{$categoryB->id}/products", ['restaurant_product_id' => $rpB->id])
            ->assertForbidden();

        CategoryProduct::query()->create(['category_id' => $categoryB->id, 'restaurant_product_id' => $rpB->id, 'sort_order' => 0]);

        $this->actingAs($manager, 'web')
            ->patchJson("/api/v1/categories/{$categoryB->id}/products/{$rpB->id}", ['sort_order' => 5])
            ->assertForbidden();

        $this->actingAs($manager, 'web')
            ->deleteJson("/api/v1/categories/{$categoryB->id}/products/{$rpB->id}")
            ->assertForbidden();
    }

    public function test_manager_cannot_manage_restaurant_bs_products_or_modifiers(): void
    {
        [$organization, , $manager, , $restaurantB] = $this->twoRestaurantsWithScopedManager();
        $productB = $this->createProduct($organization);
        $rpB = $this->createRestaurantProduct($restaurantB, $productB);
        $groupB = $this->createModifierGroup($rpB, 'Extras', maxSelect: 2);
        $optionB = $this->createModifierOption($groupB, 'Cheese');

        // A different, not-yet-attached product, so this hits the Policy
        // (403) instead of a request-validation "already taken" (422).
        $anotherProductB = $this->createProduct($organization);

        $this->actingAs($manager, 'web')
            ->postJson("/api/v1/restaurants/{$restaurantB->id}/products", [
                'product_id' => $anotherProductB->id,
                'price' => 5,
            ])
            ->assertForbidden();

        $this->actingAs($manager, 'web')
            ->patchJson("/api/v1/restaurant-products/{$rpB->id}", ['price' => 0.01])
            ->assertForbidden();

        $this->actingAs($manager, 'web')
            ->postJson("/api/v1/restaurant-products/{$rpB->id}/modifier-groups", [
                'internal_name' => 'Sneaky group',
                'max_select' => 1,
                'translations' => [['locale' => 'en', 'name' => 'Sneaky group']],
            ])
            ->assertForbidden();

        $this->actingAs($manager, 'web')
            ->patchJson("/api/v1/modifier-groups/{$groupB->id}", ['max_select' => 9])
            ->assertForbidden();

        $this->actingAs($manager, 'web')
            ->postJson("/api/v1/modifier-groups/{$groupB->id}/options", [
                'internal_name' => 'Sneaky option',
                'translations' => [['locale' => 'en', 'name' => 'Sneaky option']],
            ])
            ->assertForbidden();

        $this->actingAs($manager, 'web')
            ->patchJson("/api/v1/modifier-options/{$optionB->id}", ['price_delta' => 100])
            ->assertForbidden();
    }

    public function test_manager_cannot_read_restaurant_bs_catalog_either(): void
    {
        [, , $manager, , $restaurantB] = $this->twoRestaurantsWithScopedManager();
        $menuB = $this->createMenu($restaurantB);
        $categoryB = $this->createCategory($menuB);

        $this->actingAs($manager, 'web')
            ->getJson("/api/v1/restaurants/{$restaurantB->id}/categories")
            ->assertForbidden();

        $this->actingAs($manager, 'web')
            ->getJson("/api/v1/categories/{$categoryB->id}")
            ->assertForbidden();

        $this->actingAs($manager, 'web')
            ->getJson("/api/v1/restaurants/{$restaurantB->id}/products")
            ->assertForbidden();
    }

    // --- Owner (organization-wide role) is unaffected: both restaurants ----

    public function test_owner_manages_both_restaurants_without_restriction(): void
    {
        [$organization, $owner, , $restaurantA, $restaurantB] = $this->twoRestaurantsWithScopedManager();

        foreach ([$restaurantA, $restaurantB] as $restaurant) {
            $this->actingAs($owner, 'web')
                ->postJson("/api/v1/restaurants/{$restaurant->id}/menu", ['name' => 'Carta '.$restaurant->id])
                ->assertCreated();

            $this->actingAs($owner, 'web')
                ->postJson("/api/v1/restaurants/{$restaurant->id}/categories", [
                    'slug' => 'cat-'.$restaurant->id,
                    'translations' => [['locale' => 'en', 'name' => 'Cat']],
                ])
                ->assertCreated();
        }
    }
}
