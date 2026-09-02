<?php

namespace Tests\Feature\Tenancy;

use App\Models\Organization;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Explicit cross-tenant security scenario:
 *
 * Organization A / User A / Restaurant A
 * Organization B / User B / Restaurant B
 *
 * User A must never be able to read, list, or modify Restaurant B.
 */
class RestaurantApiIsolationTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    public function test_user_a_cannot_read_restaurant_b(): void
    {
        [, $userA] = $this->createOrganizationWithOwnerAndRestaurant();
        [, , $restaurantB] = $this->createOrganizationWithOwnerAndRestaurant();

        $this->actingAs($userA, 'web')
            ->getJson("/api/v1/restaurants/{$restaurantB->id}")
            ->assertNotFound();
    }

    public function test_user_a_cannot_update_restaurant_b(): void
    {
        [, $userA] = $this->createOrganizationWithOwnerAndRestaurant();
        [, , $restaurantB] = $this->createOrganizationWithOwnerAndRestaurant();

        $this->actingAs($userA, 'web')
            ->patchJson("/api/v1/restaurants/{$restaurantB->id}", ['name' => 'Pwned'])
            ->assertNotFound();

        $this->assertDatabaseMissing('restaurants', ['name' => 'Pwned']);
    }

    public function test_user_a_listing_restaurants_never_includes_restaurant_b(): void
    {
        [, $userA, $restaurantA] = $this->createOrganizationWithOwnerAndRestaurant();
        [, , $restaurantB] = $this->createOrganizationWithOwnerAndRestaurant();

        $response = $this->actingAs($userA, 'web')
            ->getJson('/api/v1/restaurants')
            ->assertOk();

        $ids = collect($response->json('data.restaurants'))->pluck('id');

        $this->assertTrue($ids->contains($restaurantA->id));
        $this->assertFalse($ids->contains($restaurantB->id));
    }

    /**
     * @return array{0: Organization, 1: User, 2: Restaurant}
     */
    private function createOrganizationWithOwnerAndRestaurant(): array
    {
        $organization = Organization::factory()->create();
        $owner = User::factory()->create();
        $this->assignRole($owner, 'owner', $organization);
        $restaurant = Restaurant::factory()->create(['organization_id' => $organization->id]);

        return [$organization, $owner, $restaurant];
    }
}
