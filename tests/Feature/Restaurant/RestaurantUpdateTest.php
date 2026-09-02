<?php

namespace Tests\Feature\Restaurant;

use App\Models\Organization;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class RestaurantUpdateTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    public function test_owner_can_update_a_restaurant_of_their_own_organization(): void
    {
        $organization = Organization::factory()->create();
        $owner = User::factory()->create();
        $this->assignRole($owner, 'owner', $organization);
        $restaurant = Restaurant::factory()->create(['organization_id' => $organization->id]);

        $this->actingAs($owner, 'web')
            ->patchJson("/api/v1/restaurants/{$restaurant->id}", ['name' => 'Renamed Branch'])
            ->assertOk()
            ->assertJsonPath('data.restaurant.name', 'Renamed Branch');

        $this->assertDatabaseHas('restaurants', [
            'id' => $restaurant->id,
            'name' => 'Renamed Branch',
        ]);
    }

    public function test_manager_can_update_a_restaurant_of_their_own_organization(): void
    {
        $organization = Organization::factory()->create();
        $manager = User::factory()->create();
        $this->assignRole($manager, 'manager', $organization);
        $restaurant = Restaurant::factory()->create(['organization_id' => $organization->id]);

        $this->actingAs($manager, 'web')
            ->patchJson("/api/v1/restaurants/{$restaurant->id}", ['name' => 'Renamed Branch'])
            ->assertOk();
    }

    public function test_user_cannot_update_a_restaurant_from_another_organization(): void
    {
        $organizationA = Organization::factory()->create();
        $ownerA = User::factory()->create();
        $this->assignRole($ownerA, 'owner', $organizationA);

        $organizationB = Organization::factory()->create();
        $restaurantB = Restaurant::factory()->create(['organization_id' => $organizationB->id]);

        $this->actingAs($ownerA, 'web')
            ->patchJson("/api/v1/restaurants/{$restaurantB->id}", ['name' => 'Hacked Name'])
            ->assertNotFound();

        $this->assertDatabaseMissing('restaurants', ['name' => 'Hacked Name']);
    }

    public function test_user_without_manage_restaurants_permission_cannot_update_a_restaurant(): void
    {
        $organization = Organization::factory()->create();
        $waiter = User::factory()->create();
        $this->assignRole($waiter, 'waiter', $organization);
        $restaurant = Restaurant::factory()->create(['organization_id' => $organization->id]);

        $this->actingAs($waiter, 'web')
            ->patchJson("/api/v1/restaurants/{$restaurant->id}", ['name' => 'Hacked Name'])
            ->assertForbidden();

        $this->assertDatabaseMissing('restaurants', ['name' => 'Hacked Name']);
    }

    public function test_organization_id_cannot_be_changed_via_request(): void
    {
        $organization = Organization::factory()->create();
        $owner = User::factory()->create();
        $this->assignRole($owner, 'owner', $organization);
        $restaurant = Restaurant::factory()->create(['organization_id' => $organization->id]);

        $otherOrganization = Organization::factory()->create();

        $this->actingAs($owner, 'web')
            ->patchJson("/api/v1/restaurants/{$restaurant->id}", [
                'organization_id' => $otherOrganization->id,
                'name' => 'Still Mine',
            ])
            ->assertOk();

        $this->assertDatabaseHas('restaurants', [
            'id' => $restaurant->id,
            'organization_id' => $organization->id,
            'name' => 'Still Mine',
        ]);
    }

    public function test_slug_must_remain_unique_within_the_same_organization_on_update(): void
    {
        $organization = Organization::factory()->create();
        $owner = User::factory()->create();
        $this->assignRole($owner, 'owner', $organization);

        $organization->restaurants()->create(['name' => 'A', 'slug' => 'branch-a']);
        $restaurantB = $organization->restaurants()->create(['name' => 'B', 'slug' => 'branch-b']);

        $this->actingAs($owner, 'web')
            ->patchJson("/api/v1/restaurants/{$restaurantB->id}", ['slug' => 'branch-a'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('slug');
    }
}
