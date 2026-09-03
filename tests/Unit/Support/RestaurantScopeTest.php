<?php

namespace Tests\Unit\Support;

use App\Models\Restaurant;
use App\Models\Role;
use App\Models\UserRole;
use App\Support\Restaurants\RestaurantScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * accessibleRestaurantIds()'s two sources of truth (Bloco 18): an
 * organization-wide user_roles row (restaurant_id null) -> null; otherwise
 * restaurant_users membership, never user_roles.restaurant_id directly.
 */
class RestaurantScopeTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
    }

    public function test_owner_is_organization_wide(): void
    {
        [$organization, $owner] = $this->createTenant();

        $this->assertNull(RestaurantScope::accessibleRestaurantIds($owner, $organization));
    }

    public function test_staff_in_two_restaurants_sees_both_and_not_a_third(): void
    {
        [$organization, $owner, $restaurantA] = $this->createTenant();
        $restaurantB = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $restaurantC = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $carlos = $this->createStaffAcrossRestaurants($organization, [$restaurantA, $restaurantB], 'waiter', $owner);

        $ids = RestaurantScope::accessibleRestaurantIds($carlos, $organization);

        $this->assertNotNull($ids);
        $this->assertEqualsCanonicalizing([$restaurantA->id, $restaurantB->id], $ids);
        $this->assertFalse(in_array($restaurantC->id, $ids, true));
    }

    public function test_staff_in_one_restaurant_sees_only_that_one(): void
    {
        [$organization, , $restaurant] = $this->createTenant();
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');

        $this->assertSame([$restaurant->id], RestaurantScope::accessibleRestaurantIds($waiter, $organization));
    }

    /**
     * The hardening's core guarantee: if restaurant_users and
     * user_roles.restaurant_id were ever to desync (a bug, a manual DB
     * edit — CreateStaffAction/UpdateStaffAction always keep them 1:1 in
     * normal operation), scope must still follow restaurant_users alone.
     * A user_roles row for B with no matching restaurant_users row must
     * never grant access to B.
     */
    public function test_desynced_user_roles_never_widen_scope_beyond_restaurant_users(): void
    {
        [$organization, , $restaurantA] = $this->createTenant();
        $restaurantB = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $waiter = $this->createStaff($organization, $restaurantA, 'waiter', 'W-A');

        // Simulate desync: an extra user_roles row for B with no
        // restaurant_users row to back it.
        $role = Role::query()->where('slug', 'waiter')->firstOrFail();
        UserRole::query()->create([
            'user_id' => $waiter->id,
            'role_id' => $role->id,
            'organization_id' => $organization->id,
            'restaurant_id' => $restaurantB->id,
        ]);

        $ids = RestaurantScope::accessibleRestaurantIds($waiter, $organization);

        $this->assertSame([$restaurantA->id], $ids);
        $this->assertFalse(in_array($restaurantB->id, $ids, true));
    }

    public function test_can_access_restaurant_matches_accessible_ids(): void
    {
        [$organization, $owner, $restaurantA] = $this->createTenant();
        $restaurantB = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $carlos = $this->createStaffAcrossRestaurants($organization, [$restaurantA], 'waiter', $owner);

        $this->assertTrue(RestaurantScope::canAccessRestaurant($carlos, $restaurantA));
        $this->assertFalse(RestaurantScope::canAccessRestaurant($carlos, $restaurantB));
        $this->assertTrue(RestaurantScope::canAccessRestaurant($owner, $restaurantB));
    }
}
