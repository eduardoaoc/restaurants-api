<?php

namespace Tests\Concerns;

use App\Actions\Catalog\CreateCategoryAction;
use App\Actions\Catalog\CreateModifierGroupAction;
use App\Actions\Catalog\CreateModifierOptionAction;
use App\Actions\Catalog\CreateProductAction;
use App\Actions\Staff\CreateStaffAction;
use App\Models\Category;
use App\Models\Menu;
use App\Models\ModifierGroup;
use App\Models\ModifierOption;
use App\Models\Organization;
use App\Models\Product;
use App\Models\Restaurant;
use App\Models\RestaurantProduct;
use App\Models\Role;
use App\Models\Table;
use App\Models\User;
use App\Models\UserRole;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;

trait InteractsWithTenants
{
    /**
     * Seed the roles/permissions catalog used to authorize requests.
     */
    protected function seedRolesAndPermissions(): void
    {
        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class]);
    }

    /**
     * Attach a user to an organization and grant them a role in a given context.
     */
    protected function assignRole(User $user, string $roleSlug, Organization $organization, ?Restaurant $restaurant = null): UserRole
    {
        if (! $user->organizations()->whereKey($organization->id)->exists()) {
            $organization->users()->attach($user);
        }

        $role = Role::query()->where('slug', $roleSlug)->firstOrFail();

        return UserRole::query()->create([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'organization_id' => $organization->id,
            'restaurant_id' => $restaurant?->id,
        ]);
    }

    /**
     * Create a fully-wired operational staff member (user + organization_users
     * + restaurant_users + user_roles) via the real creation action.
     */
    protected function createStaff(
        Organization $organization,
        Restaurant $restaurant,
        string $role,
        string $subId,
        ?string $email = null,
        ?string $name = null,
    ): User {
        return app(CreateStaffAction::class)->execute($organization, [
            'name' => $name ?? 'Staff Member',
            'email' => $email ?? sprintf('staff-%s@example.com', uniqid()),
            'password' => 'password123',
            'restaurant_id' => $restaurant->id,
            'role' => $role,
            'sub_id' => $subId,
        ]);
    }

    /**
     * Create a fresh Organization + Restaurant + owner user, all wired up.
     *
     * @return array{0: Organization, 1: User, 2: Restaurant}
     */
    protected function createTenant(): array
    {
        $organization = Organization::factory()->create();
        $owner = User::factory()->create();
        $this->assignRole($owner, 'owner', $organization);
        $restaurant = Restaurant::factory()->create(['organization_id' => $organization->id]);

        return [$organization, $owner, $restaurant];
    }

    /**
     * Create a table for a restaurant with a properly generated public token.
     */
    protected function createTable(Restaurant $restaurant, ?string $name = null, ?int $number = null): Table
    {
        return $restaurant->tables()->create([
            'name' => $name ?? 'Mesa '.uniqid(),
            'number' => $number,
            'public_token' => Table::generateUniquePublicToken(),
        ]);
    }

    /**
     * Create the (single) menu of a restaurant.
     */
    protected function createMenu(Restaurant $restaurant, string $name = 'Main Menu'): Menu
    {
        return $restaurant->menu()->create(['name' => $name, 'status' => 'active']);
    }

    /**
     * Create a category with translations under a menu.
     *
     * @param  array<int, array{locale: string, name: string, description?: ?string}>|null  $translations
     */
    protected function createCategory(Menu $menu, ?string $slug = null, ?array $translations = null): Category
    {
        return app(CreateCategoryAction::class)->execute($menu, [
            'slug' => $slug ?? 'category-'.uniqid(),
            'translations' => $translations ?? [
                ['locale' => 'en', 'name' => 'Starters'],
            ],
        ]);
    }

    /**
     * Create a product with translations under an organization's catalog.
     *
     * @param  array<int, array{locale: string, name: string, description?: ?string}>|null  $translations
     */
    protected function createProduct(Organization $organization, ?string $internalName = null, ?array $translations = null): Product
    {
        return app(CreateProductAction::class)->execute($organization, [
            'internal_name' => $internalName ?? 'Product '.uniqid(),
            'translations' => $translations ?? [
                ['locale' => 'en', 'name' => 'Cola'],
            ],
        ]);
    }

    /**
     * Attach a product to a restaurant with a price.
     */
    protected function createRestaurantProduct(Restaurant $restaurant, Product $product, float $price = 10.0, bool $available = true): RestaurantProduct
    {
        return RestaurantProduct::query()->create([
            'restaurant_id' => $restaurant->id,
            'product_id' => $product->id,
            'price' => $price,
            'available' => $available,
        ]);
    }

    /**
     * Create a fresh Organization + owner + Restaurant + Product + RestaurantProduct.
     *
     * @return array{0: Organization, 1: User, 2: Restaurant, 3: RestaurantProduct}
     */
    protected function createTenantWithRestaurantProduct(): array
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $product = $this->createProduct($organization);
        $restaurantProduct = $this->createRestaurantProduct($restaurant, $product);

        return [$organization, $owner, $restaurant, $restaurantProduct];
    }

    /**
     * Create a modifier group with translations under a restaurant product.
     *
     * @param  array<int, array{locale: string, name: string, description?: ?string}>|null  $translations
     */
    protected function createModifierGroup(
        RestaurantProduct $restaurantProduct,
        ?string $internalName = null,
        int $minSelect = 0,
        int $maxSelect = 1,
        bool $required = false,
        ?array $translations = null,
    ): ModifierGroup {
        return app(CreateModifierGroupAction::class)->execute($restaurantProduct, [
            'internal_name' => $internalName ?? 'Group '.uniqid(),
            'min_select' => $minSelect,
            'max_select' => $maxSelect,
            'required' => $required,
            'translations' => $translations ?? [
                ['locale' => 'en', 'name' => 'Extras'],
            ],
        ]);
    }

    /**
     * Create a modifier option with translations under a modifier group.
     *
     * @param  array<int, array{locale: string, name: string, description?: ?string}>|null  $translations
     */
    protected function createModifierOption(
        ModifierGroup $modifierGroup,
        ?string $internalName = null,
        float $priceDelta = 0.0,
        ?array $translations = null,
    ): ModifierOption {
        return app(CreateModifierOptionAction::class)->execute($modifierGroup, [
            'internal_name' => $internalName ?? 'Option '.uniqid(),
            'price_delta' => $priceDelta,
            'translations' => $translations ?? [
                ['locale' => 'en', 'name' => 'Bacon'],
            ],
        ]);
    }
}
