<?php

namespace Tests\Feature\Restaurant;

use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * RestaurantController::show()/update()/index() previously resolved a
 * Restaurant scoped only to the active Organization, never to
 * RestaurantScope — a manager restricted to Restaurant A could GET/PATCH
 * Restaurant B of the same organization (confirmed 200, not even 403).
 * This file locks in the fix: out-of-scope restaurants now resolve as 404
 * via the scoped query, before the Policy ever runs — exactly like
 * Orders/Staff/Dashboard/Settings.
 */
class RestaurantCrudScopeTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    public function test_manager_scoped_to_a_gets_404_viewing_restaurant_b(): void
    {
        [$organization, , $restaurantA] = $this->createTenant();
        $restaurantB = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $managerA = $this->createStaff($organization, $restaurantA, 'manager', 'M-A');

        $this->actingAs($managerA, 'web')
            ->getJson("/api/v1/restaurants/{$restaurantB->id}")
            ->assertNotFound();
    }

    public function test_manager_scoped_to_a_gets_404_updating_restaurant_b_and_nothing_changes(): void
    {
        [$organization, , $restaurantA] = $this->createTenant();
        $restaurantB = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $managerA = $this->createStaff($organization, $restaurantA, 'manager', 'M-A');
        $originalName = $restaurantB->name;

        $this->actingAs($managerA, 'web')
            ->patchJson("/api/v1/restaurants/{$restaurantB->id}", ['name' => 'Hacked Name'])
            ->assertNotFound();

        $this->assertSame($originalName, $restaurantB->fresh()->name);
    }

    public function test_manager_scoped_to_a_can_view_and_update_restaurant_a(): void
    {
        [$organization, , $restaurantA] = $this->createTenant();
        $managerA = $this->createStaff($organization, $restaurantA, 'manager', 'M-A');

        $this->actingAs($managerA, 'web')
            ->getJson("/api/v1/restaurants/{$restaurantA->id}")
            ->assertOk()
            ->assertJsonPath('data.restaurant.id', $restaurantA->id);

        $this->actingAs($managerA, 'web')
            ->patchJson("/api/v1/restaurants/{$restaurantA->id}", ['name' => 'Renamed'])
            ->assertOk()
            ->assertJsonPath('data.restaurant.name', 'Renamed');
    }

    /**
     * Same restaurant, but the requester lacks manage_restaurants: 403,
     * distinct from the 404 an out-of-scope restaurant produces.
     */
    public function test_staff_of_own_restaurant_without_manage_restaurants_gets_403_updating_it(): void
    {
        [$organization, , $restaurant] = $this->createTenant();
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');

        $this->actingAs($waiter, 'web')
            ->patchJson("/api/v1/restaurants/{$restaurant->id}", ['name' => 'Hacked'])
            ->assertForbidden();
    }

    public function test_owner_can_view_and_update_any_restaurant_in_their_organization(): void
    {
        [$organization, $owner, $restaurantA] = $this->createTenant();
        $restaurantB = Restaurant::factory()->create(['organization_id' => $organization->id]);

        $this->actingAs($owner, 'web')->getJson("/api/v1/restaurants/{$restaurantA->id}")->assertOk();
        $this->actingAs($owner, 'web')->getJson("/api/v1/restaurants/{$restaurantB->id}")->assertOk();
        $this->actingAs($owner, 'web')->patchJson("/api/v1/restaurants/{$restaurantA->id}", ['name' => 'A2'])->assertOk();
        $this->actingAs($owner, 'web')->patchJson("/api/v1/restaurants/{$restaurantB->id}", ['name' => 'B2'])->assertOk();
    }

    // --- Index (section 14): scoped listing -----------------------------

    public function test_staff_in_two_restaurants_lists_exactly_those_two_and_not_a_third(): void
    {
        [$organization, $owner, $restaurantA] = $this->createTenant();
        $restaurantB = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $restaurantC = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $carlos = $this->createStaffAcrossRestaurants($organization, [$restaurantA, $restaurantB], 'waiter', $owner);

        $response = $this->actingAs($carlos, 'web')->getJson('/api/v1/restaurants')->assertOk();

        $ids = collect($response->json('data.restaurants'))->pluck('id')->sort()->values()->all();
        $this->assertSame([$restaurantA->id, $restaurantB->id], $ids);
    }

    public function test_owner_lists_every_restaurant_of_their_organization(): void
    {
        [$organization, $owner, $restaurantA] = $this->createTenant();
        $restaurantB = Restaurant::factory()->create(['organization_id' => $organization->id]);

        $response = $this->actingAs($owner, 'web')->getJson('/api/v1/restaurants')->assertOk();

        $ids = collect($response->json('data.restaurants'))->pluck('id')->sort()->values()->all();
        $this->assertSame([$restaurantA->id, $restaurantB->id], $ids);
    }
}
