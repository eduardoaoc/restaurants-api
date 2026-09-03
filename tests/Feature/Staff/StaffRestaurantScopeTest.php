<?php

namespace Tests\Feature\Staff;

use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * StaffController::staffQuery() previously filtered only by organization,
 * never by RestaurantScope — a manager restricted to one restaurant could
 * view, edit, and even create staff in another restaurant of the same
 * organization (confirmed: GET/PATCH returned 200, POST returned 201, not
 * even a 403). This file locks in the fix: out-of-scope staff/restaurants
 * now resolve as 404 via the scoped query, before the Policy ever runs —
 * exactly like Orders/TableRequests/StaffPerformance.
 */
class StaffRestaurantScopeTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    public function test_manager_can_view_staff_of_their_own_restaurant(): void
    {
        [$organization, , $restaurantA] = $this->createTenant();
        $managerA = $this->createStaff($organization, $restaurantA, 'manager', 'M-A');
        $staffA = $this->createStaff($organization, $restaurantA, 'waiter', 'W-A');

        $this->actingAs($managerA, 'web')
            ->getJson("/api/v1/staff/{$staffA->id}")
            ->assertOk()
            ->assertJsonPath('data.staff.id', $staffA->id);
    }

    public function test_manager_gets_not_found_viewing_staff_of_another_restaurant(): void
    {
        [$organization, , $restaurantA] = $this->createTenant();
        $restaurantB = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $managerA = $this->createStaff($organization, $restaurantA, 'manager', 'M-A');
        $staffB = $this->createStaff($organization, $restaurantB, 'waiter', 'W-B');

        $this->actingAs($managerA, 'web')
            ->getJson("/api/v1/staff/{$staffB->id}")
            ->assertNotFound();
    }

    public function test_manager_can_update_staff_of_their_own_restaurant(): void
    {
        [$organization, , $restaurantA] = $this->createTenant();
        $managerA = $this->createStaff($organization, $restaurantA, 'manager', 'M-A');
        $staffA = $this->createStaff($organization, $restaurantA, 'waiter', 'W-A');

        $this->actingAs($managerA, 'web')
            ->patchJson("/api/v1/staff/{$staffA->id}", ['name' => 'Renamed'])
            ->assertOk()
            ->assertJsonPath('data.staff.name', 'Renamed');
    }

    public function test_manager_gets_not_found_updating_staff_of_another_restaurant_and_nothing_changes(): void
    {
        [$organization, , $restaurantA] = $this->createTenant();
        $restaurantB = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $managerA = $this->createStaff($organization, $restaurantA, 'manager', 'M-A');
        $staffB = $this->createStaff($organization, $restaurantB, 'waiter', 'W-B');
        $originalName = $staffB->name;

        $this->actingAs($managerA, 'web')
            ->patchJson("/api/v1/staff/{$staffB->id}", ['name' => 'Hacked Name'])
            ->assertNotFound();

        $this->assertSame($originalName, $staffB->fresh()->name);
    }

    public function test_manager_gets_not_found_creating_staff_in_another_restaurant(): void
    {
        [$organization, , $restaurantA] = $this->createTenant();
        $restaurantB = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $managerA = $this->createStaff($organization, $restaurantA, 'manager', 'M-A');

        $this->actingAs($managerA, 'web')
            ->postJson('/api/v1/staff', [
                'name' => 'Injected',
                'email' => 'injected-'.uniqid().'@example.com',
                'password' => 'password123',
                'role' => 'waiter',
                'restaurant_assignments' => [
                    ['restaurant_id' => $restaurantB->id, 'sub_id' => 'INJ-1'],
                ],
            ])
            ->assertNotFound();

        $this->assertDatabaseMissing('users', ['name' => 'Injected']);
    }

    public function test_manager_can_create_staff_in_their_own_restaurant(): void
    {
        [$organization, , $restaurantA] = $this->createTenant();
        $managerA = $this->createStaff($organization, $restaurantA, 'manager', 'M-A');

        $this->actingAs($managerA, 'web')
            ->postJson('/api/v1/staff', [
                'name' => 'New Waiter',
                'email' => 'new-waiter-'.uniqid().'@example.com',
                'password' => 'password123',
                'role' => 'waiter',
                'restaurant_assignments' => [
                    ['restaurant_id' => $restaurantA->id, 'sub_id' => 'NW-1'],
                ],
            ])
            ->assertCreated();
    }

    public function test_manager_listing_staff_only_sees_their_own_restaurant(): void
    {
        [$organization, , $restaurantA] = $this->createTenant();
        $restaurantB = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $managerA = $this->createStaff($organization, $restaurantA, 'manager', 'M-A');
        $staffA = $this->createStaff($organization, $restaurantA, 'waiter', 'W-A');
        $this->createStaff($organization, $restaurantB, 'waiter', 'W-B');

        $response = $this->actingAs($managerA, 'web')
            ->getJson('/api/v1/staff')
            ->assertOk();

        $ids = $response->json('data.staff.*.id');
        $this->assertContains($managerA->id, $ids);
        $this->assertContains($staffA->id, $ids);
        $this->assertCount(2, $ids);
    }

    public function test_owner_can_view_and_update_staff_across_all_restaurants_of_the_organization(): void
    {
        [$organization, $owner, $restaurantA] = $this->createTenant();
        $restaurantB = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $staffA = $this->createStaff($organization, $restaurantA, 'waiter', 'W-A');
        $staffB = $this->createStaff($organization, $restaurantB, 'waiter', 'W-B');

        $this->actingAs($owner, 'web')->getJson("/api/v1/staff/{$staffA->id}")->assertOk();
        $this->actingAs($owner, 'web')->getJson("/api/v1/staff/{$staffB->id}")->assertOk();
        $this->actingAs($owner, 'web')->patchJson("/api/v1/staff/{$staffA->id}", ['name' => 'A2'])->assertOk();
        $this->actingAs($owner, 'web')->patchJson("/api/v1/staff/{$staffB->id}", ['name' => 'B2'])->assertOk();
    }

    public function test_owner_can_create_staff_in_any_restaurant_of_the_organization(): void
    {
        [$organization, $owner, $restaurantA] = $this->createTenant();
        $restaurantB = Restaurant::factory()->create(['organization_id' => $organization->id]);

        $this->actingAs($owner, 'web')
            ->postJson('/api/v1/staff', [
                'name' => 'Owner Created',
                'email' => 'owner-created-'.uniqid().'@example.com',
                'password' => 'password123',
                'role' => 'waiter',
                'restaurant_assignments' => [
                    ['restaurant_id' => $restaurantB->id, 'sub_id' => 'OC-1'],
                ],
            ])
            ->assertCreated();
    }

    public function test_owner_gets_not_found_for_staff_of_another_organization(): void
    {
        [$organization, $owner] = $this->createTenant();
        [$otherOrganization, , $otherRestaurant] = $this->createTenant();
        $otherStaff = $this->createStaff($otherOrganization, $otherRestaurant, 'waiter', 'W-1');

        $this->actingAs($owner, 'web')->getJson("/api/v1/staff/{$otherStaff->id}")->assertNotFound();
        $this->actingAs($owner, 'web')->patchJson("/api/v1/staff/{$otherStaff->id}", ['name' => 'X'])->assertNotFound();
    }

    public function test_staff_of_their_own_restaurant_without_manage_users_gets_forbidden(): void
    {
        [$organization, , $restaurant] = $this->createTenant();
        $waiterA = $this->createStaff($organization, $restaurant, 'waiter', 'W-A');
        $waiterB = $this->createStaff($organization, $restaurant, 'waiter', 'W-B');

        $this->actingAs($waiterA, 'web')
            ->getJson("/api/v1/staff/{$waiterB->id}")
            ->assertForbidden();

        $this->actingAs($waiterA, 'web')
            ->patchJson("/api/v1/staff/{$waiterB->id}", ['name' => 'X'])
            ->assertForbidden();
    }

    /**
     * Scope is checked before permission: a requester with no manage_users
     * permission at all, targeting a staff member of a restaurant they
     * cannot even reach, must still get 404 — never 403 — because the
     * query never finds the target in the first place.
     */
    public function test_scope_is_checked_before_permission(): void
    {
        [$organization, , $restaurantA] = $this->createTenant();
        $restaurantB = Restaurant::factory()->create(['organization_id' => $organization->id]);
        // Waiter has no manage_users permission at all.
        $waiterA = $this->createStaff($organization, $restaurantA, 'waiter', 'W-A');
        $staffB = $this->createStaff($organization, $restaurantB, 'waiter', 'W-B');

        $this->actingAs($waiterA, 'web')
            ->getJson("/api/v1/staff/{$staffB->id}")
            ->assertNotFound();

        $this->actingAs($waiterA, 'web')
            ->patchJson("/api/v1/staff/{$staffB->id}", ['name' => 'X'])
            ->assertNotFound();
    }

    // --- Multi-restaurant staff (Bloco 18) -----------------------------

    /**
     * A staff member in A+B appears exactly once in the index, never
     * duplicated because of two restaurant_users rows.
     */
    public function test_staff_in_multiple_restaurants_appears_once_in_the_index(): void
    {
        [$organization, $owner, $restaurantA] = $this->createTenant();
        $restaurantB = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $carlos = $this->createStaffAcrossRestaurants($organization, [$restaurantA, $restaurantB], 'waiter', $owner);

        $response = $this->actingAs($owner, 'web')->getJson('/api/v1/staff')->assertOk();

        $ids = $response->json('data.staff.*.id');
        $this->assertSame(1, count(array_filter($ids, fn ($id) => $id === $carlos->id)));
    }

    /**
     * A manager scoped to A alone can still find (and manage) a staff
     * member who is in A+B, because whereHas('restaurants', ...) matches
     * on ANY reachable restaurant, not on the full set.
     */
    public function test_manager_of_a_can_view_staff_who_is_also_in_b(): void
    {
        [$organization, $owner, $restaurantA] = $this->createTenant();
        $restaurantB = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $managerA = $this->createStaff($organization, $restaurantA, 'manager', 'M-A');
        $carlos = $this->createStaffAcrossRestaurants($organization, [$restaurantA, $restaurantB], 'waiter', $owner);

        $this->actingAs($managerA, 'web')
            ->getJson("/api/v1/staff/{$carlos->id}")
            ->assertOk()
            ->assertJsonPath('data.staff.id', $carlos->id);
    }

    /**
     * Owner explicitly assigning A+B+C does not turn into an implicit
     * "all restaurants" grant — a Restaurant D created afterwards is not
     * automatically added (see report — no wildcard/all_restaurants flag).
     */
    public function test_staff_explicit_assignment_does_not_grow_when_a_new_restaurant_is_created_later(): void
    {
        [$organization, $owner, $restaurantA] = $this->createTenant();
        $restaurantB = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $restaurantC = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $carlos = $this->createStaffAcrossRestaurants($organization, [$restaurantA, $restaurantB, $restaurantC], 'waiter', $owner);

        $restaurantD = Restaurant::factory()->create(['organization_id' => $organization->id]);

        $restaurantIds = $carlos->restaurants()->pluck('restaurants.id')->sort()->values()->all();
        $this->assertSame([$restaurantA->id, $restaurantB->id, $restaurantC->id], $restaurantIds);
        $this->assertFalse(in_array($restaurantD->id, $restaurantIds, true));
    }
}
