<?php

namespace Tests\Feature\Restaurant;

use App\Models\Organization;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class RestaurantIndexTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    public function test_user_lists_only_restaurants_of_their_own_organization(): void
    {
        $organizationA = Organization::factory()->create();
        $ownerA = User::factory()->create();
        $this->assignRole($ownerA, 'owner', $organizationA);
        $restaurantsA = Restaurant::factory()->count(2)->create(['organization_id' => $organizationA->id]);

        $organizationB = Organization::factory()->create();
        $ownerB = User::factory()->create();
        $this->assignRole($ownerB, 'owner', $organizationB);
        Restaurant::factory()->create(['organization_id' => $organizationB->id]);

        $response = $this->actingAs($ownerA, 'web')
            ->getJson('/api/v1/restaurants')
            ->assertOk();

        $ids = collect($response->json('data.restaurants'))->pluck('id')->sort()->values();

        $this->assertSame($restaurantsA->pluck('id')->sort()->values()->all(), $ids->all());
    }

    public function test_unauthenticated_user_cannot_list_restaurants(): void
    {
        $this->getJson('/api/v1/restaurants')->assertUnauthorized();
    }
}
