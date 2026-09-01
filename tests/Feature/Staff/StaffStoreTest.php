<?php

namespace Tests\Feature\Staff;

use App\Models\Organization;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class StaffStoreTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    public function test_owner_can_create_a_waiter(): void
    {
        $organization = Organization::factory()->create();
        $owner = User::factory()->create();
        $this->assignRole($owner, 'owner', $organization);
        $restaurant = Restaurant::factory()->create(['organization_id' => $organization->id]);

        $this->actingAs($owner, 'web')
            ->postJson('/api/v1/staff', [
                'name' => 'Carlos',
                'email' => 'carlos@example.com',
                'password' => 'TemporaryPassword123!',
                'restaurant_id' => $restaurant->id,
                'role' => 'waiter',
                'sub_id' => 'W-023',
            ])
            ->assertCreated()
            ->assertJsonPath('data.staff.name', 'Carlos')
            ->assertJsonPath('data.staff.email', 'carlos@example.com')
            ->assertJsonPath('data.staff.sub_id', 'W-023')
            ->assertJsonPath('data.staff.role.slug', 'waiter')
            ->assertJsonPath('data.staff.restaurant.id', $restaurant->id)
            ->assertJsonMissingPath('data.staff.password');
    }

    public function test_manager_with_manage_users_can_create_staff(): void
    {
        $organization = Organization::factory()->create();
        $manager = User::factory()->create();
        $this->assignRole($manager, 'manager', $organization);
        $restaurant = Restaurant::factory()->create(['organization_id' => $organization->id]);

        $this->actingAs($manager, 'web')
            ->postJson('/api/v1/staff', [
                'name' => 'Ana',
                'email' => 'ana@example.com',
                'password' => 'TemporaryPassword123!',
                'restaurant_id' => $restaurant->id,
                'role' => 'kitchen',
                'sub_id' => 'K-001',
            ])
            ->assertCreated();
    }

    public function test_waiter_without_permission_cannot_create_staff(): void
    {
        $organization = Organization::factory()->create();
        $restaurant = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');

        $this->actingAs($waiter, 'web')
            ->postJson('/api/v1/staff', [
                'name' => 'Ana',
                'email' => 'ana@example.com',
                'password' => 'TemporaryPassword123!',
                'restaurant_id' => $restaurant->id,
                'role' => 'kitchen',
                'sub_id' => 'K-001',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'ana@example.com']);
    }

    public function test_creates_all_four_tenant_links_in_one_go(): void
    {
        $organization = Organization::factory()->create();
        $owner = User::factory()->create();
        $this->assignRole($owner, 'owner', $organization);
        $restaurant = Restaurant::factory()->create(['organization_id' => $organization->id]);

        $this->actingAs($owner, 'web')
            ->postJson('/api/v1/staff', [
                'name' => 'Carlos',
                'email' => 'carlos@example.com',
                'password' => 'TemporaryPassword123!',
                'restaurant_id' => $restaurant->id,
                'role' => 'waiter',
                'sub_id' => 'W-023',
            ])
            ->assertCreated();

        $user = User::query()->where('email', 'carlos@example.com')->firstOrFail();

        $this->assertDatabaseHas('organization_users', [
            'organization_id' => $organization->id,
            'user_id' => $user->id,
        ]);
        $this->assertDatabaseHas('restaurant_users', [
            'restaurant_id' => $restaurant->id,
            'user_id' => $user->id,
            'sub_id' => 'W-023',
        ]);
        $this->assertDatabaseHas('user_roles', [
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'restaurant_id' => $restaurant->id,
        ]);

        $role = $user->roles()->first();
        $this->assertSame('waiter', $role->slug);
    }

    public function test_password_is_stored_hashed(): void
    {
        $organization = Organization::factory()->create();
        $owner = User::factory()->create();
        $this->assignRole($owner, 'owner', $organization);
        $restaurant = Restaurant::factory()->create(['organization_id' => $organization->id]);

        $this->actingAs($owner, 'web')
            ->postJson('/api/v1/staff', [
                'name' => 'Carlos',
                'email' => 'carlos@example.com',
                'password' => 'TemporaryPassword123!',
                'restaurant_id' => $restaurant->id,
                'role' => 'waiter',
                'sub_id' => 'W-023',
            ])
            ->assertCreated();

        $user = User::query()->where('email', 'carlos@example.com')->firstOrFail();

        $this->assertNotSame('TemporaryPassword123!', $user->password);
        $this->assertTrue(Hash::check('TemporaryPassword123!', $user->password));
    }

    public function test_restaurant_from_another_organization_is_rejected(): void
    {
        $organization = Organization::factory()->create();
        $owner = User::factory()->create();
        $this->assignRole($owner, 'owner', $organization);

        $otherOrganization = Organization::factory()->create();
        $otherRestaurant = Restaurant::factory()->create(['organization_id' => $otherOrganization->id]);

        $this->actingAs($owner, 'web')
            ->postJson('/api/v1/staff', [
                'name' => 'Carlos',
                'email' => 'carlos@example.com',
                'password' => 'TemporaryPassword123!',
                'restaurant_id' => $otherRestaurant->id,
                'role' => 'waiter',
                'sub_id' => 'W-023',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('restaurant_id');

        $this->assertDatabaseMissing('users', ['email' => 'carlos@example.com']);
    }

    public function test_owner_role_cannot_be_created_via_staff_endpoint(): void
    {
        $organization = Organization::factory()->create();
        $owner = User::factory()->create();
        $this->assignRole($owner, 'owner', $organization);
        $restaurant = Restaurant::factory()->create(['organization_id' => $organization->id]);

        $this->actingAs($owner, 'web')
            ->postJson('/api/v1/staff', [
                'name' => 'Another Owner',
                'email' => 'owner2@example.com',
                'password' => 'TemporaryPassword123!',
                'restaurant_id' => $restaurant->id,
                'role' => 'owner',
                'sub_id' => 'O-1',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('role');
    }

    public function test_duplicate_email_returns_a_validation_error(): void
    {
        $organization = Organization::factory()->create();
        $owner = User::factory()->create();
        $this->assignRole($owner, 'owner', $organization);
        $restaurant = Restaurant::factory()->create(['organization_id' => $organization->id]);

        User::factory()->create(['email' => 'carlos@example.com']);

        $this->actingAs($owner, 'web')
            ->postJson('/api/v1/staff', [
                'name' => 'Carlos',
                'email' => 'carlos@example.com',
                'password' => 'TemporaryPassword123!',
                'restaurant_id' => $restaurant->id,
                'role' => 'waiter',
                'sub_id' => 'W-023',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    public function test_duplicate_sub_id_within_the_same_restaurant_returns_a_validation_error(): void
    {
        $organization = Organization::factory()->create();
        $owner = User::factory()->create();
        $this->assignRole($owner, 'owner', $organization);
        $restaurant = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $this->createStaff($organization, $restaurant, 'waiter', 'W-023');

        $this->actingAs($owner, 'web')
            ->postJson('/api/v1/staff', [
                'name' => 'Second Waiter',
                'email' => 'second@example.com',
                'password' => 'TemporaryPassword123!',
                'restaurant_id' => $restaurant->id,
                'role' => 'waiter',
                'sub_id' => 'W-023',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('sub_id');
    }

    public function test_same_sub_id_can_exist_in_a_different_restaurant(): void
    {
        $organization = Organization::factory()->create();
        $owner = User::factory()->create();
        $this->assignRole($owner, 'owner', $organization);
        $restaurantA = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $restaurantB = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $this->createStaff($organization, $restaurantA, 'waiter', 'W-1');

        $this->actingAs($owner, 'web')
            ->postJson('/api/v1/staff', [
                'name' => 'Another Waiter',
                'email' => 'another@example.com',
                'password' => 'TemporaryPassword123!',
                'restaurant_id' => $restaurantB->id,
                'role' => 'waiter',
                'sub_id' => 'W-1',
            ])
            ->assertCreated();
    }
}
