<?php

namespace Tests\Feature\Staff;

use App\Models\Organization;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class StaffShowTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    public function test_owner_can_view_staff_of_their_own_organization(): void
    {
        $organization = Organization::factory()->create();
        $owner = User::factory()->create();
        $this->assignRole($owner, 'owner', $organization);
        $restaurant = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $staff = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');

        $this->actingAs($owner, 'web')
            ->getJson("/api/v1/staff/{$staff->id}")
            ->assertOk()
            ->assertJsonPath('data.staff.id', $staff->id)
            ->assertJsonPath('data.staff.role.slug', 'waiter');
    }

    public function test_manager_with_manage_users_can_view_staff(): void
    {
        $organization = Organization::factory()->create();
        $manager = User::factory()->create();
        $this->assignRole($manager, 'manager', $organization);
        $restaurant = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $staff = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');

        $this->actingAs($manager, 'web')
            ->getJson("/api/v1/staff/{$staff->id}")
            ->assertOk();
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
            ->getJson("/api/v1/staff/{$staffB->id}")
            ->assertNotFound();
    }

    public function test_waiter_without_permission_receives_forbidden(): void
    {
        $organization = Organization::factory()->create();
        $restaurant = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');
        $cashier = $this->createStaff($organization, $restaurant, 'cashier', 'C-1');

        $this->actingAs($waiter, 'web')
            ->getJson("/api/v1/staff/{$cashier->id}")
            ->assertForbidden();
    }
}
