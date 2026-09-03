<?php

namespace Tests\Feature\Restaurant;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class RestaurantStoreTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    public function test_owner_can_create_a_restaurant(): void
    {
        $organization = Organization::factory()->create();
        $owner = User::factory()->create();
        $this->assignRole($owner, 'owner', $organization);

        $this->actingAs($owner, 'web')
            ->postJson('/api/v1/restaurants', [
                'name' => 'Downtown Branch',
                'slug' => 'downtown-branch',
            ])
            ->assertCreated()
            ->assertJsonPath('data.restaurant.name', 'Downtown Branch')
            ->assertJsonPath('data.restaurant.organization_id', $organization->id);

        $this->assertDatabaseHas('restaurants', [
            'organization_id' => $organization->id,
            'slug' => 'downtown-branch',
        ]);
    }

    public function test_organization_id_sent_in_the_payload_is_ignored(): void
    {
        $organization = Organization::factory()->create();
        $owner = User::factory()->create();
        $this->assignRole($owner, 'owner', $organization);

        $otherOrganization = Organization::factory()->create();

        $this->actingAs($owner, 'web')
            ->postJson('/api/v1/restaurants', [
                'organization_id' => $otherOrganization->id,
                'name' => 'Downtown Branch',
                'slug' => 'downtown-branch',
            ])
            ->assertCreated()
            ->assertJsonPath('data.restaurant.organization_id', $organization->id);

        $this->assertDatabaseHas('restaurants', [
            'slug' => 'downtown-branch',
            'organization_id' => $organization->id,
        ]);
        $this->assertDatabaseMissing('restaurants', [
            'slug' => 'downtown-branch',
            'organization_id' => $otherOrganization->id,
        ]);
    }

    public function test_slug_must_be_unique_within_the_same_organization(): void
    {
        $organization = Organization::factory()->create();
        $owner = User::factory()->create();
        $this->assignRole($owner, 'owner', $organization);

        $organization->restaurants()->create([
            'name' => 'Existing',
            'slug' => 'downtown-branch',
        ]);

        $this->actingAs($owner, 'web')
            ->postJson('/api/v1/restaurants', [
                'name' => 'Another',
                'slug' => 'downtown-branch',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('slug');
    }

    public function test_slug_can_repeat_across_different_organizations(): void
    {
        $otherOrganization = Organization::factory()->create();
        $otherOrganization->restaurants()->create([
            'name' => 'Other Org Branch',
            'slug' => 'downtown-branch',
        ]);

        $organization = Organization::factory()->create();
        $owner = User::factory()->create();
        $this->assignRole($owner, 'owner', $organization);

        $this->actingAs($owner, 'web')
            ->postJson('/api/v1/restaurants', [
                'name' => 'Downtown Branch',
                'slug' => 'downtown-branch',
            ])
            ->assertCreated();
    }

    public function test_user_without_manage_restaurants_permission_cannot_create_a_restaurant(): void
    {
        $organization = Organization::factory()->create();
        $waiter = User::factory()->create();
        $this->assignRole($waiter, 'waiter', $organization);

        $this->actingAs($waiter, 'web')
            ->postJson('/api/v1/restaurants', [
                'name' => 'Downtown Branch',
                'slug' => 'downtown-branch',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('restaurants', ['slug' => 'downtown-branch']);
    }
}
