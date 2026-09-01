<?php

namespace Tests\Feature\Restaurant;

use App\Models\Organization;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class RestaurantShowTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    public function test_user_can_view_a_restaurant_of_their_own_organization(): void
    {
        $organization = Organization::factory()->create();
        $owner = User::factory()->create();
        $this->assignRole($owner, 'owner', $organization);
        $restaurant = Restaurant::factory()->create(['organization_id' => $organization->id]);

        $this->actingAs($owner, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}")
            ->assertOk()
            ->assertJsonPath('data.restaurant.id', $restaurant->id);
    }

    public function test_user_cannot_view_a_restaurant_from_another_organization(): void
    {
        $organizationA = Organization::factory()->create();
        $ownerA = User::factory()->create();
        $this->assignRole($ownerA, 'owner', $organizationA);

        $organizationB = Organization::factory()->create();
        $restaurantB = Restaurant::factory()->create(['organization_id' => $organizationB->id]);

        $this->actingAs($ownerA, 'web')
            ->getJson("/api/v1/restaurants/{$restaurantB->id}")
            ->assertNotFound();
    }

    public function test_viewing_a_nonexistent_restaurant_returns_not_found(): void
    {
        $organization = Organization::factory()->create();
        $owner = User::factory()->create();
        $this->assignRole($owner, 'owner', $organization);

        $this->actingAs($owner, 'web')
            ->getJson('/api/v1/restaurants/999999')
            ->assertNotFound();
    }
}
