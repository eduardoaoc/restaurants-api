<?php

namespace Tests\Feature\Staff;

use App\Models\Organization;
use App\Models\Restaurant;
use App\Models\User;
use App\Support\Staff\EmployeeCodeGenerator;
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

        $response = $this->actingAs($owner, 'web')
            ->postJson('/api/v1/staff', [
                'name' => 'Carlos',
                'email' => 'carlos@example.com',
                'password' => 'TemporaryPassword123!',
                'role' => 'waiter',
                'restaurant_assignments' => [
                    ['restaurant_id' => $restaurant->id],
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('data.staff.name', 'Carlos')
            ->assertJsonPath('data.staff.email', 'carlos@example.com')
            ->assertJsonPath('data.staff.role.slug', 'waiter')
            ->assertJsonPath('data.staff.restaurants.0.id', $restaurant->id)
            ->assertJsonMissingPath('data.staff.password');

        $staffId = $response->json('data.staff.id');
        $this->assertSame('E-'.$staffId, $response->json('data.staff.restaurants.0.sub_id'));
        $this->assertSame($staffId, User::query()->where('email', 'carlos@example.com')->value('id'));
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
                'role' => 'kitchen',
                'restaurant_assignments' => [
                    ['restaurant_id' => $restaurant->id],
                ],
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
                'role' => 'kitchen',
                'restaurant_assignments' => [
                    ['restaurant_id' => $restaurant->id],
                ],
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
                'role' => 'waiter',
                'restaurant_assignments' => [
                    ['restaurant_id' => $restaurant->id],
                ],
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
            'sub_id' => 'E-'.$user->id,
        ]);
        $this->assertDatabaseHas('user_roles', [
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'restaurant_id' => $restaurant->id,
        ]);

        $role = $user->roles()->first();
        $this->assertSame('waiter', $role->slug);
    }

    public function test_creates_a_staff_member_assigned_to_multiple_restaurants(): void
    {
        $organization = Organization::factory()->create();
        $owner = User::factory()->create();
        $this->assignRole($owner, 'owner', $organization);
        $restaurantA = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $restaurantB = Restaurant::factory()->create(['organization_id' => $organization->id]);

        $response = $this->actingAs($owner, 'web')
            ->postJson('/api/v1/staff', [
                'name' => 'Carlos',
                'email' => 'carlos@example.com',
                'password' => 'TemporaryPassword123!',
                'role' => 'waiter',
                'restaurant_assignments' => [
                    ['restaurant_id' => $restaurantA->id],
                    ['restaurant_id' => $restaurantB->id],
                ],
            ])
            ->assertCreated();

        $restaurantIds = collect($response->json('data.staff.restaurants'))->pluck('id')->sort()->values()->all();
        $this->assertSame([$restaurantA->id, $restaurantB->id], $restaurantIds);

        $user = User::query()->where('email', 'carlos@example.com')->firstOrFail();
        $this->assertDatabaseHas('restaurant_users', ['user_id' => $user->id, 'restaurant_id' => $restaurantA->id, 'sub_id' => 'E-'.$user->id]);
        $this->assertDatabaseHas('restaurant_users', ['user_id' => $user->id, 'restaurant_id' => $restaurantB->id, 'sub_id' => 'E-'.$user->id]);
        $this->assertDatabaseHas('user_roles', ['user_id' => $user->id, 'restaurant_id' => $restaurantA->id]);
        $this->assertDatabaseHas('user_roles', ['user_id' => $user->id, 'restaurant_id' => $restaurantB->id]);
    }

    public function test_empty_restaurant_assignments_is_rejected(): void
    {
        $organization = Organization::factory()->create();
        $owner = User::factory()->create();
        $this->assignRole($owner, 'owner', $organization);

        $this->actingAs($owner, 'web')
            ->postJson('/api/v1/staff', [
                'name' => 'Carlos',
                'email' => 'carlos@example.com',
                'password' => 'TemporaryPassword123!',
                'role' => 'waiter',
                'restaurant_assignments' => [],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('restaurant_assignments');

        $this->assertDatabaseMissing('users', ['email' => 'carlos@example.com']);
    }

    public function test_duplicate_restaurant_id_within_the_payload_is_rejected(): void
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
                'role' => 'waiter',
                'restaurant_assignments' => [
                    ['restaurant_id' => $restaurant->id],
                    ['restaurant_id' => $restaurant->id],
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('restaurant_assignments.0.restaurant_id');
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
                'role' => 'waiter',
                'restaurant_assignments' => [
                    ['restaurant_id' => $restaurant->id],
                ],
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
                'role' => 'waiter',
                'restaurant_assignments' => [
                    ['restaurant_id' => $otherRestaurant->id],
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('restaurant_assignments.0.restaurant_id');

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
                'role' => 'owner',
                'restaurant_assignments' => [
                    ['restaurant_id' => $restaurant->id],
                ],
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
                'role' => 'waiter',
                'restaurant_assignments' => [
                    ['restaurant_id' => $restaurant->id],
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    public function test_client_cannot_choose_sub_id_or_user_id(): void
    {
        $organization = Organization::factory()->create();
        $owner = User::factory()->create();
        $this->assignRole($owner, 'owner', $organization);
        $restaurant = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $this->createStaff($organization, $restaurant, 'waiter', 'W-023');

        $response = $this->actingAs($owner, 'web')
            ->postJson('/api/v1/staff', [
                'id' => 999999,
                'user_id' => 999999,
                'name' => 'Second Waiter',
                'email' => 'second@example.com',
                'password' => 'TemporaryPassword123!',
                'role' => 'waiter',
                'restaurant_assignments' => [
                    ['restaurant_id' => $restaurant->id, 'sub_id' => 'W-023'],
                ],
            ])
            ->assertCreated();

        $user = User::query()->where('email', 'second@example.com')->firstOrFail();
        $this->assertNotSame(999999, $user->id);
        $this->assertSame($user->id, $response->json('data.staff.id'));
        $this->assertSame('E-'.$user->id, $response->json('data.staff.restaurants.0.sub_id'));
        // The pre-existing manual code is untouched.
        $this->assertDatabaseHas('restaurant_users', ['restaurant_id' => $restaurant->id, 'sub_id' => 'W-023']);
    }

    public function test_generated_sub_id_skips_a_legacy_code_that_collides_with_it(): void
    {
        $organization = Organization::factory()->create();
        $restaurant = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $newcomer = User::factory()->create();
        // A legacy manual code that happens to equal the derived one.
        $this->createStaff($organization, $restaurant, 'waiter', 'E-'.$newcomer->id);

        $generator = app(EmployeeCodeGenerator::class);

        $this->assertSame('E-'.$newcomer->id.'-2', $generator->generate($restaurant->id, $newcomer->id));
        // Another restaurant has no collision.
        $other = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $this->assertSame('E-'.$newcomer->id, $generator->generate($other->id, $newcomer->id));
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
                'role' => 'waiter',
                'restaurant_assignments' => [
                    ['restaurant_id' => $restaurantB->id],
                ],
            ])
            ->assertCreated();
    }
}
