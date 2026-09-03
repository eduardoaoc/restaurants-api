<?php

namespace App\Support\Restaurants;

use App\Models\Organization;
use App\Models\Restaurant;
use App\Models\User;

/**
 * Determines which restaurants of an organization a user may operate on
 * for restaurant-scoped operational data (Orders).
 *
 * Three separate concepts (Bloco 18 — see report):
 *   - Organization membership (organization_users) — is this user part of
 *     the tenant at all. Not this class's concern.
 *   - Restaurant membership/scope (restaurant_users) — which specific
 *     restaurants an operational user may operate on. THIS class.
 *   - Role/permission (user_roles + roles.permissions) — what an
 *     authenticated user is allowed to DO, entirely independent of which
 *     restaurants they can reach. Membership in a restaurant never widens
 *     what a role permits, and a permission never widens which restaurants
 *     are reachable.
 *
 * A user holding at least one organization-wide role assignment
 * (user_roles.restaurant_id is null — this is how the owner is set up; see
 * InteractsWithTenants::assignRole()) may operate on every restaurant of
 * the organization, resolved dynamically (never one restaurant_users row
 * per restaurant for the owner — see CreateStaffAction/report). Every
 * other user (operational staff) is restricted to exactly the restaurants
 * they hold an explicit restaurant_users row for — 1..N, never a wildcard.
 */
class RestaurantScope
{
    /**
     * @return array<int, int>|null null means "every restaurant in the organization"
     */
    public static function accessibleRestaurantIds(User $user, Organization $organization): ?array
    {
        $hasOrganizationWideRole = $user->userRoles()
            ->where('organization_id', $organization->id)
            ->whereNull('restaurant_id')
            ->exists();

        if ($hasOrganizationWideRole) {
            return null;
        }

        return $user->restaurants()
            ->where('restaurants.organization_id', $organization->id)
            ->pluck('restaurants.id')
            ->all();
    }

    public static function canAccessRestaurant(User $user, Restaurant $restaurant): bool
    {
        $accessible = self::accessibleRestaurantIds($user, $restaurant->organization);

        return $accessible === null || in_array($restaurant->id, $accessible, true);
    }
}
