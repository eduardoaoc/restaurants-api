<?php

namespace Tests\Feature\Category;

use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class CategoryTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    public function test_owner_can_create_a_category_with_translations(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $this->createMenu($restaurant);

        $response = $this->actingAs($owner, 'web')
            ->postJson("/api/v1/restaurants/{$restaurant->id}/categories", [
                'slug' => 'starters',
                'sort_order' => 1,
                'translations' => [
                    ['locale' => 'es', 'name' => 'Entrantes'],
                    ['locale' => 'en', 'name' => 'Starters', 'description' => 'Small dishes'],
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('data.category.slug', 'starters')
            ->assertJsonPath('data.category.sort_order', 1);

        $translations = collect($response->json('data.category.translations'));
        $this->assertSame('Entrantes', $translations->firstWhere('locale', 'es')['name']);
        $this->assertSame('Starters', $translations->firstWhere('locale', 'en')['name']);
        $this->assertSame('Small dishes', $translations->firstWhere('locale', 'en')['description']);
    }

    public function test_creating_a_category_without_a_menu_fails_with_a_clear_error(): void
    {
        [, $owner, $restaurant] = $this->createTenant();

        $this->actingAs($owner, 'web')
            ->postJson("/api/v1/restaurants/{$restaurant->id}/categories", [
                'slug' => 'starters',
                'translations' => [['locale' => 'en', 'name' => 'Starters']],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('menu');
    }

    public function test_slug_must_be_unique_within_the_menu(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $menu = $this->createMenu($restaurant);
        $this->createCategory($menu, 'starters');

        $this->actingAs($owner, 'web')
            ->postJson("/api/v1/restaurants/{$restaurant->id}/categories", [
                'slug' => 'starters',
                'translations' => [['locale' => 'en', 'name' => 'Starters Again']],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('slug');
    }

    public function test_the_same_slug_is_allowed_in_a_different_menu(): void
    {
        [$organization, $owner, $restaurantA] = $this->createTenant();
        $menuA = $this->createMenu($restaurantA);
        $this->createCategory($menuA, 'starters');

        $restaurantB = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $this->createMenu($restaurantB);

        $this->actingAs($owner, 'web')
            ->postJson("/api/v1/restaurants/{$restaurantB->id}/categories", [
                'slug' => 'starters',
                'translations' => [['locale' => 'en', 'name' => 'Starters']],
            ])
            ->assertCreated();
    }

    public function test_sort_order_is_saved(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $menu = $this->createMenu($restaurant);

        $this->actingAs($owner, 'web')
            ->postJson("/api/v1/restaurants/{$restaurant->id}/categories", [
                'slug' => 'mains',
                'sort_order' => 5,
                'translations' => [['locale' => 'en', 'name' => 'Mains']],
            ])
            ->assertCreated()
            ->assertJsonPath('data.category.sort_order', 5);
    }

    public function test_owner_can_view_a_category(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $menu = $this->createMenu($restaurant);
        $category = $this->createCategory($menu);

        $this->actingAs($owner, 'web')
            ->getJson("/api/v1/categories/{$category->id}")
            ->assertOk()
            ->assertJsonPath('data.category.id', $category->id);
    }

    public function test_owner_can_update_a_category(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $menu = $this->createMenu($restaurant);
        $category = $this->createCategory($menu, 'starters');

        $this->actingAs($owner, 'web')
            ->patchJson("/api/v1/categories/{$category->id}", [
                'sort_order' => 9,
                'translations' => [['locale' => 'en', 'name' => 'Updated Starters']],
            ])
            ->assertOk()
            ->assertJsonPath('data.category.sort_order', 9);

        $translations = collect($this->actingAs($owner, 'web')
            ->getJson("/api/v1/categories/{$category->id}")
            ->json('data.category.translations'));

        $this->assertSame('Updated Starters', $translations->firstWhere('locale', 'en')['name']);
    }

    public function test_category_from_another_organization_returns_not_found(): void
    {
        [, $ownerA] = $this->createTenant();
        [, , $restaurantB] = $this->createTenant();
        $menuB = $this->createMenu($restaurantB);
        $categoryB = $this->createCategory($menuB);

        $this->actingAs($ownerA, 'web')
            ->getJson("/api/v1/categories/{$categoryB->id}")
            ->assertNotFound();
    }

    public function test_user_without_manage_menu_permission_receives_forbidden(): void
    {
        [$organization, , $restaurant] = $this->createTenant();
        $menu = $this->createMenu($restaurant);
        $category = $this->createCategory($menu);
        $kitchen = $this->createStaff($organization, $restaurant, 'kitchen', 'K-1');

        $this->actingAs($kitchen, 'web')
            ->getJson("/api/v1/categories/{$category->id}")
            ->assertForbidden();

        $this->actingAs($kitchen, 'web')
            ->postJson("/api/v1/restaurants/{$restaurant->id}/categories", [
                'slug' => 'x',
                'translations' => [['locale' => 'en', 'name' => 'X']],
            ])
            ->assertForbidden();
    }
}
