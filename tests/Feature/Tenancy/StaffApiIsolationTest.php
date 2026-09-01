<?php

namespace Tests\Feature\Tenancy;

use App\Models\Organization;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Explicit cross-tenant security scenario for staff management:
 *
 * Organization A / Restaurant A / Owner A / Staff A
 * Organization B / Restaurant B / Owner B / Staff B
 *
 * Owner A must never be able to read, list, update, or create a staff
 * link into Organization B.
 */
class StaffApiIsolationTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    public function test_owner_a_cannot_view_staff_b(): void
    {
        [, $ownerA] = $this->createTenant();
        [$organizationB, , $restaurantB] = $this->createTenant();
        $staffB = $this->createStaff($organizationB, $restaurantB, 'waiter', 'W-1');

        $this->actingAs($ownerA, 'web')
            ->getJson("/api/v1/staff/{$staffB->id}")
            ->assertNotFound();
    }

    public function test_owner_a_cannot_update_staff_b(): void
    {
        [, $ownerA] = $this->createTenant();
        [$organizationB, , $restaurantB] = $this->createTenant();
        $staffB = $this->createStaff($organizationB, $restaurantB, 'waiter', 'W-1');

        $this->actingAs($ownerA, 'web')
            ->patchJson("/api/v1/staff/{$staffB->id}", ['name' => 'Pwned'])
            ->assertNotFound();

        $this->assertDatabaseMissing('users', ['name' => 'Pwned']);
    }

    public function test_owner_a_listing_staff_never_includes_staff_b(): void
    {
        [$organizationA, $ownerA, $restaurantA] = $this->createTenant();
        $staffA = $this->createStaff($organizationA, $restaurantA, 'waiter', 'W-1');

        [$organizationB, , $restaurantB] = $this->createTenant();
        $staffB = $this->createStaff($organizationB, $restaurantB, 'waiter', 'W-1');

        $response = $this->actingAs($ownerA, 'web')
            ->getJson('/api/v1/staff')
            ->assertOk();

        $ids = collect($response->json('data.staff'))->pluck('id');

        $this->assertTrue($ids->contains($staffA->id));
        $this->assertFalse($ids->contains($staffB->id));
    }

    public function test_owner_a_cannot_create_staff_linked_to_restaurant_b(): void
    {
        [, $ownerA] = $this->createTenant();
        [, , $restaurantB] = $this->createTenant();

        $this->actingAs($ownerA, 'web')
            ->postJson('/api/v1/staff', [
                'name' => 'Cross Tenant',
                'email' => 'cross-tenant@example.com',
                'password' => 'password123',
                'restaurant_id' => $restaurantB->id,
                'role' => 'waiter',
                'sub_id' => 'W-1',
            ])
            ->assertUnprocessable();

        $this->assertDatabaseMissing('users', ['email' => 'cross-tenant@example.com']);
        $this->assertDatabaseMissing('restaurant_users', [
            'restaurant_id' => $restaurantB->id,
            'sub_id' => 'W-1',
        ]);
    }

    /**
     * @return array{0: Organization, 1: User, 2: Restaurant}
     */
    private function createTenant(): array
    {
        $organization = Organization::factory()->create();
        $owner = User::factory()->create();
        $this->assignRole($owner, 'owner', $organization);
        $restaurant = Restaurant::factory()->create(['organization_id' => $organization->id]);

        return [$organization, $owner, $restaurant];
    }
}
