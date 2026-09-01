<?php

namespace Tests\Feature\Staff;

use App\Models\Organization;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class StaffIndexTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    public function test_owner_lists_staff(): void
    {
        $organization = Organization::factory()->create();
        $owner = User::factory()->create();
        $this->assignRole($owner, 'owner', $organization);
        $restaurant = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $staff = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');

        $response = $this->actingAs($owner, 'web')
            ->getJson('/api/v1/staff')
            ->assertOk();

        $ids = collect($response->json('data.staff'))->pluck('id');
        $this->assertTrue($ids->contains($staff->id));
    }

    public function test_manager_with_manage_users_lists_staff(): void
    {
        $organization = Organization::factory()->create();
        $manager = User::factory()->create();
        $this->assignRole($manager, 'manager', $organization);
        $restaurant = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $this->createStaff($organization, $restaurant, 'waiter', 'W-1');

        $this->actingAs($manager, 'web')
            ->getJson('/api/v1/staff')
            ->assertOk();
    }

    public function test_waiter_without_manage_users_receives_forbidden(): void
    {
        $organization = Organization::factory()->create();
        $restaurant = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');

        $this->actingAs($waiter, 'web')
            ->getJson('/api/v1/staff')
            ->assertForbidden();
    }

    public function test_kitchen_without_manage_users_receives_forbidden(): void
    {
        $organization = Organization::factory()->create();
        $restaurant = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $kitchen = $this->createStaff($organization, $restaurant, 'kitchen', 'K-1');

        $this->actingAs($kitchen, 'web')
            ->getJson('/api/v1/staff')
            ->assertForbidden();
    }

    public function test_cashier_without_manage_users_receives_forbidden(): void
    {
        $organization = Organization::factory()->create();
        $restaurant = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $cashier = $this->createStaff($organization, $restaurant, 'cashier', 'C-1');

        $this->actingAs($cashier, 'web')
            ->getJson('/api/v1/staff')
            ->assertForbidden();
    }

    public function test_staff_from_another_organization_is_not_listed(): void
    {
        $organizationA = Organization::factory()->create();
        $ownerA = User::factory()->create();
        $this->assignRole($ownerA, 'owner', $organizationA);
        $restaurantA = Restaurant::factory()->create(['organization_id' => $organizationA->id]);
        $this->createStaff($organizationA, $restaurantA, 'waiter', 'W-1');

        $organizationB = Organization::factory()->create();
        $restaurantB = Restaurant::factory()->create(['organization_id' => $organizationB->id]);
        $staffB = $this->createStaff($organizationB, $restaurantB, 'waiter', 'W-1');

        $response = $this->actingAs($ownerA, 'web')
            ->getJson('/api/v1/staff')
            ->assertOk();

        $ids = collect($response->json('data.staff'))->pluck('id');
        $this->assertFalse($ids->contains($staffB->id));
    }

    public function test_owner_does_not_appear_as_operational_staff(): void
    {
        $organization = Organization::factory()->create();
        $owner = User::factory()->create();
        $this->assignRole($owner, 'owner', $organization);
        $restaurant = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');

        $response = $this->actingAs($owner, 'web')
            ->getJson('/api/v1/staff')
            ->assertOk();

        $ids = collect($response->json('data.staff'))->pluck('id');
        $this->assertFalse($ids->contains($owner->id));
        $this->assertTrue($ids->contains($waiter->id));
    }

    public function test_owner_authorization_comes_from_the_seeded_permission_not_a_role_bypass(): void
    {
        $organization = Organization::factory()->create();
        $owner = User::factory()->create();
        $this->assignRole($owner, 'owner', $organization);

        $this->assertTrue($owner->hasPermission('manage_users', $organization));
    }
}
