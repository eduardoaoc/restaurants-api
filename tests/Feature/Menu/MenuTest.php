<?php

namespace Tests\Feature\Menu;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class MenuTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    public function test_owner_can_create_the_menu_of_a_restaurant(): void
    {
        [, $owner, $restaurant] = $this->createTenant();

        $this->actingAs($owner, 'web')
            ->postJson("/api/v1/restaurants/{$restaurant->id}/menu", ['name' => 'Main Menu'])
            ->assertCreated()
            ->assertJsonPath('data.menu.restaurant_id', $restaurant->id)
            ->assertJsonPath('data.menu.name', 'Main Menu')
            ->assertJsonPath('data.menu.status', 'active');
    }

    public function test_a_restaurant_can_only_have_one_menu(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $this->createMenu($restaurant);

        $this->actingAs($owner, 'web')
            ->postJson("/api/v1/restaurants/{$restaurant->id}/menu", ['name' => 'Second Menu'])
            ->assertStatus(409);

        $this->assertDatabaseCount('menus', 1);
    }

    public function test_owner_can_view_the_menu(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $menu = $this->createMenu($restaurant);

        $this->actingAs($owner, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/menu")
            ->assertOk()
            ->assertJsonPath('data.menu.id', $menu->id);
    }

    public function test_viewing_a_menu_that_does_not_exist_yet_returns_not_found(): void
    {
        [, $owner, $restaurant] = $this->createTenant();

        $this->actingAs($owner, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/menu")
            ->assertNotFound();
    }

    public function test_owner_can_update_the_menu(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $this->createMenu($restaurant);

        $this->actingAs($owner, 'web')
            ->patchJson("/api/v1/restaurants/{$restaurant->id}/menu", ['status' => 'inactive'])
            ->assertOk()
            ->assertJsonPath('data.menu.status', 'inactive');
    }

    public function test_menu_belongs_to_the_correct_restaurant(): void
    {
        [, $owner, $restaurantA] = $this->createTenant();
        [, , $restaurantB] = $this->createTenant();
        $this->createMenu($restaurantA);
        $this->createMenu($restaurantB);

        $response = $this->actingAs($owner, 'web')
            ->getJson("/api/v1/restaurants/{$restaurantA->id}/menu")
            ->assertOk();

        $this->assertSame($restaurantA->id, $response->json('data.menu.restaurant_id'));
    }

    public function test_menu_of_another_organization_returns_not_found(): void
    {
        [, $ownerA] = $this->createTenant();
        [, , $restaurantB] = $this->createTenant();
        $this->createMenu($restaurantB);

        $this->actingAs($ownerA, 'web')
            ->getJson("/api/v1/restaurants/{$restaurantB->id}/menu")
            ->assertNotFound();
    }

    public function test_user_without_manage_menu_permission_cannot_view_the_menu(): void
    {
        [$organization, , $restaurant] = $this->createTenant();
        $this->createMenu($restaurant);
        $kitchen = $this->createStaff($organization, $restaurant, 'kitchen', 'K-1');

        $this->actingAs($kitchen, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/menu")
            ->assertForbidden();
    }

    public function test_user_without_manage_menu_permission_cannot_update_the_menu(): void
    {
        [$organization, , $restaurant] = $this->createTenant();
        $this->createMenu($restaurant);
        $kitchen = $this->createStaff($organization, $restaurant, 'kitchen', 'K-1');

        $this->actingAs($kitchen, 'web')
            ->patchJson("/api/v1/restaurants/{$restaurant->id}/menu", ['status' => 'inactive'])
            ->assertForbidden();
    }
}
