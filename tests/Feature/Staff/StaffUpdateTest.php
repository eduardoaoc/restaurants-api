<?php

namespace Tests\Feature\Staff;

use App\Models\Organization;
use App\Models\Restaurant;
use App\Models\RestaurantUser;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class StaffUpdateTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    public function test_owner_can_update_name(): void
    {
        $organization = Organization::factory()->create();
        $owner = User::factory()->create();
        $this->assignRole($owner, 'owner', $organization);
        $restaurant = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $staff = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');

        $this->actingAs($owner, 'web')
            ->patchJson("/api/v1/staff/{$staff->id}", ['name' => 'New Name'])
            ->assertOk()
            ->assertJsonPath('data.staff.name', 'New Name');

        $this->assertDatabaseHas('users', ['id' => $staff->id, 'name' => 'New Name']);
    }

    public function test_owner_can_update_email(): void
    {
        $organization = Organization::factory()->create();
        $owner = User::factory()->create();
        $this->assignRole($owner, 'owner', $organization);
        $restaurant = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $staff = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');

        $this->actingAs($owner, 'web')
            ->patchJson("/api/v1/staff/{$staff->id}", ['email' => 'updated@example.com'])
            ->assertOk()
            ->assertJsonPath('data.staff.email', 'updated@example.com');

        $this->assertDatabaseHas('users', ['id' => $staff->id, 'email' => 'updated@example.com']);
    }

    public function test_owner_can_change_the_restaurant(): void
    {
        $organization = Organization::factory()->create();
        $owner = User::factory()->create();
        $this->assignRole($owner, 'owner', $organization);
        $restaurantA = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $restaurantB = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $staff = $this->createStaff($organization, $restaurantA, 'waiter', 'W-1');

        $this->actingAs($owner, 'web')
            ->patchJson("/api/v1/staff/{$staff->id}", [
                'restaurant_assignments' => [
                    ['restaurant_id' => $restaurantB->id, 'sub_id' => 'W-1'],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.staff.restaurants.0.id', $restaurantB->id);

        $this->assertDatabaseHas('restaurant_users', [
            'user_id' => $staff->id,
            'restaurant_id' => $restaurantB->id,
        ]);
        $this->assertDatabaseMissing('restaurant_users', [
            'user_id' => $staff->id,
            'restaurant_id' => $restaurantA->id,
        ]);
        $this->assertDatabaseHas('user_roles', [
            'user_id' => $staff->id,
            'organization_id' => $organization->id,
            'restaurant_id' => $restaurantB->id,
        ]);
        $this->assertDatabaseMissing('user_roles', [
            'user_id' => $staff->id,
            'restaurant_id' => $restaurantA->id,
        ]);
    }

    public function test_owner_can_add_a_second_restaurant(): void
    {
        $organization = Organization::factory()->create();
        $owner = User::factory()->create();
        $this->assignRole($owner, 'owner', $organization);
        $restaurantA = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $restaurantB = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $staff = $this->createStaff($organization, $restaurantA, 'waiter', 'W-A');

        $response = $this->actingAs($owner, 'web')
            ->patchJson("/api/v1/staff/{$staff->id}", [
                'restaurant_assignments' => [
                    ['restaurant_id' => $restaurantA->id, 'sub_id' => 'W-A'],
                    ['restaurant_id' => $restaurantB->id, 'sub_id' => 'W-B'],
                ],
            ])
            ->assertOk();

        $restaurantIds = collect($response->json('data.staff.restaurants'))->pluck('id')->sort()->values()->all();
        $this->assertSame([$restaurantA->id, $restaurantB->id], $restaurantIds);

        $this->assertDatabaseHas('restaurant_users', ['user_id' => $staff->id, 'restaurant_id' => $restaurantA->id]);
        $this->assertDatabaseHas('restaurant_users', ['user_id' => $staff->id, 'restaurant_id' => $restaurantB->id]);
    }

    public function test_replacing_a_plus_b_with_b_plus_c_removes_a_keeps_b_and_adds_c(): void
    {
        $organization = Organization::factory()->create();
        $owner = User::factory()->create();
        $this->assignRole($owner, 'owner', $organization);
        $restaurantA = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $restaurantB = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $restaurantC = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $staff = $this->createStaffAcrossRestaurants($organization, [$restaurantA, $restaurantB], 'waiter', $owner);

        $this->actingAs($owner, 'web')
            ->patchJson("/api/v1/staff/{$staff->id}", [
                'restaurant_assignments' => [
                    ['restaurant_id' => $restaurantB->id, 'sub_id' => 'MS-'.$restaurantB->id],
                    ['restaurant_id' => $restaurantC->id, 'sub_id' => 'MS-'.$restaurantC->id],
                ],
            ])
            ->assertOk();

        $this->assertDatabaseMissing('restaurant_users', ['user_id' => $staff->id, 'restaurant_id' => $restaurantA->id]);
        $this->assertDatabaseHas('restaurant_users', ['user_id' => $staff->id, 'restaurant_id' => $restaurantB->id]);
        $this->assertDatabaseHas('restaurant_users', ['user_id' => $staff->id, 'restaurant_id' => $restaurantC->id]);
        $this->assertDatabaseMissing('user_roles', ['user_id' => $staff->id, 'restaurant_id' => $restaurantA->id]);
        $this->assertDatabaseHas('user_roles', ['user_id' => $staff->id, 'restaurant_id' => $restaurantB->id]);
        $this->assertDatabaseHas('user_roles', ['user_id' => $staff->id, 'restaurant_id' => $restaurantC->id]);
        $this->assertSame(2, RestaurantUser::query()->where('user_id', $staff->id)->count());
    }

    public function test_empty_restaurant_assignments_is_rejected(): void
    {
        $organization = Organization::factory()->create();
        $owner = User::factory()->create();
        $this->assignRole($owner, 'owner', $organization);
        $restaurant = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $staff = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');

        $this->actingAs($owner, 'web')
            ->patchJson("/api/v1/staff/{$staff->id}", ['restaurant_assignments' => []])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('restaurant_assignments');

        $this->assertDatabaseHas('restaurant_users', ['user_id' => $staff->id, 'restaurant_id' => $restaurant->id]);
    }

    public function test_owner_can_change_the_role(): void
    {
        $organization = Organization::factory()->create();
        $owner = User::factory()->create();
        $this->assignRole($owner, 'owner', $organization);
        $restaurant = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $staff = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');

        $this->actingAs($owner, 'web')
            ->patchJson("/api/v1/staff/{$staff->id}", ['role' => 'cashier'])
            ->assertOk()
            ->assertJsonPath('data.staff.role.slug', 'cashier');
    }

    public function test_role_change_applies_to_every_assigned_restaurant(): void
    {
        $organization = Organization::factory()->create();
        $owner = User::factory()->create();
        $this->assignRole($owner, 'owner', $organization);
        $restaurantA = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $restaurantB = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $staff = $this->createStaffAcrossRestaurants($organization, [$restaurantA, $restaurantB], 'waiter', $owner);

        $this->actingAs($owner, 'web')
            ->patchJson("/api/v1/staff/{$staff->id}", ['role' => 'cashier'])
            ->assertOk();

        $roleSlugs = UserRole::query()->where('user_id', $staff->id)->with('role')->get()->pluck('role.slug')->unique();
        $this->assertSame(['cashier'], $roleSlugs->values()->all());
    }

    public function test_owner_can_change_the_sub_id(): void
    {
        $organization = Organization::factory()->create();
        $owner = User::factory()->create();
        $this->assignRole($owner, 'owner', $organization);
        $restaurant = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $staff = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');

        $this->actingAs($owner, 'web')
            ->patchJson("/api/v1/staff/{$staff->id}", [
                'restaurant_assignments' => [
                    ['restaurant_id' => $restaurant->id, 'sub_id' => 'W-999'],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.staff.restaurants.0.sub_id', 'W-999');

        $this->assertDatabaseHas('restaurant_users', [
            'user_id' => $staff->id,
            'sub_id' => 'W-999',
        ]);
    }

    public function test_manager_with_manage_users_can_update_staff(): void
    {
        $organization = Organization::factory()->create();
        $manager = User::factory()->create();
        $this->assignRole($manager, 'manager', $organization);
        $restaurant = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $staff = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');

        $this->actingAs($manager, 'web')
            ->patchJson("/api/v1/staff/{$staff->id}", ['name' => 'Updated'])
            ->assertOk();
    }

    public function test_links_remain_consistent_after_changing_restaurant_and_role(): void
    {
        $organization = Organization::factory()->create();
        $owner = User::factory()->create();
        $this->assignRole($owner, 'owner', $organization);
        $restaurantA = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $restaurantB = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $staff = $this->createStaff($organization, $restaurantA, 'waiter', 'W-1');

        $this->actingAs($owner, 'web')
            ->patchJson("/api/v1/staff/{$staff->id}", [
                'role' => 'kitchen',
                'restaurant_assignments' => [
                    ['restaurant_id' => $restaurantB->id, 'sub_id' => 'K-5'],
                ],
            ])
            ->assertOk();

        $restaurantUser = RestaurantUser::query()->where('user_id', $staff->id)->firstOrFail();
        $userRole = UserRole::query()->where('user_id', $staff->id)->firstOrFail();

        $this->assertSame($restaurantB->id, $restaurantUser->restaurant_id);
        $this->assertSame($restaurantB->id, $userRole->restaurant_id);
        $this->assertSame($organization->id, $userRole->organization_id);
        $this->assertSame('K-5', $restaurantUser->sub_id);
        $this->assertSame('kitchen', $userRole->role->slug);
    }

    public function test_restaurant_from_another_organization_is_rejected(): void
    {
        $organization = Organization::factory()->create();
        $owner = User::factory()->create();
        $this->assignRole($owner, 'owner', $organization);
        $restaurant = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $staff = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');

        $otherOrganization = Organization::factory()->create();
        $otherRestaurant = Restaurant::factory()->create(['organization_id' => $otherOrganization->id]);

        $this->actingAs($owner, 'web')
            ->patchJson("/api/v1/staff/{$staff->id}", [
                'restaurant_assignments' => [
                    ['restaurant_id' => $otherRestaurant->id, 'sub_id' => 'W-1'],
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('restaurant_assignments.0.restaurant_id');
    }

    public function test_staff_from_another_organization_returns_not_found(): void
    {
        $organizationA = Organization::factory()->create();
        $ownerA = User::factory()->create();
        $this->assignRole($ownerA, 'owner', $organizationA);

        $organizationB = Organization::factory()->create();
        $restaurantB = Restaurant::factory()->create(['organization_id' => $organizationB->id]);
        $staffB = $this->createStaff($organizationB, $restaurantB, 'waiter', 'W-1');

        $this->actingAs($ownerA, 'web')
            ->patchJson("/api/v1/staff/{$staffB->id}", ['name' => 'Hacked'])
            ->assertNotFound();

        $this->assertDatabaseMissing('users', ['name' => 'Hacked']);
    }

    public function test_waiter_without_permission_receives_forbidden(): void
    {
        $organization = Organization::factory()->create();
        $restaurant = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');
        $cashier = $this->createStaff($organization, $restaurant, 'cashier', 'C-1');

        $this->actingAs($waiter, 'web')
            ->patchJson("/api/v1/staff/{$cashier->id}", ['name' => 'Hacked'])
            ->assertForbidden();
    }
}
