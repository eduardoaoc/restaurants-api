<?php

namespace Tests\Feature\Organization;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class OrganizationTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    public function test_authenticated_user_can_view_their_organization(): void
    {
        $organization = Organization::factory()->create(['name' => 'Grupo Exemplo']);
        $owner = User::factory()->create();
        $this->assignRole($owner, 'owner', $organization);

        $this->actingAs($owner, 'web')
            ->getJson('/api/v1/organization')
            ->assertOk()
            ->assertJsonPath('data.organization.id', $organization->id)
            ->assertJsonPath('data.organization.name', 'Grupo Exemplo')
            ->assertJsonMissingPath('data.organization.deleted_at');
    }

    public function test_unauthenticated_user_cannot_view_organization(): void
    {
        $this->getJson('/api/v1/organization')->assertUnauthorized();
    }

    public function test_owner_can_update_organization(): void
    {
        $organization = Organization::factory()->create();
        $owner = User::factory()->create();
        $this->assignRole($owner, 'owner', $organization);

        $this->actingAs($owner, 'web')
            ->patchJson('/api/v1/organization', [
                'name' => 'Updated Name',
                'status' => 'inactive',
            ])
            ->assertOk()
            ->assertJsonPath('data.organization.name', 'Updated Name')
            ->assertJsonPath('data.organization.status', 'inactive');

        $this->assertDatabaseHas('organizations', [
            'id' => $organization->id,
            'name' => 'Updated Name',
            'status' => 'inactive',
        ]);
    }

    public function test_user_without_manage_organization_permission_cannot_update_organization(): void
    {
        $organization = Organization::factory()->create();
        $manager = User::factory()->create();
        $this->assignRole($manager, 'manager', $organization);

        $this->actingAs($manager, 'web')
            ->patchJson('/api/v1/organization', ['name' => 'Hacked Name'])
            ->assertForbidden();

        $this->assertDatabaseMissing('organizations', ['name' => 'Hacked Name']);
    }

    public function test_invalid_slug_returns_a_validation_error(): void
    {
        $organization = Organization::factory()->create();
        $owner = User::factory()->create();
        $this->assignRole($owner, 'owner', $organization);

        $this->actingAs($owner, 'web')
            ->patchJson('/api/v1/organization', ['slug' => 'Not A Valid Slug!'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('slug');
    }

    public function test_duplicate_slug_returns_a_validation_error(): void
    {
        $otherOrganization = Organization::factory()->create(['slug' => 'taken-slug']);

        $organization = Organization::factory()->create();
        $owner = User::factory()->create();
        $this->assignRole($owner, 'owner', $organization);

        $this->actingAs($owner, 'web')
            ->patchJson('/api/v1/organization', ['slug' => 'taken-slug'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('slug');
    }

    public function test_user_without_any_organization_receives_forbidden(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'web')
            ->getJson('/api/v1/organization')
            ->assertForbidden();
    }
}
