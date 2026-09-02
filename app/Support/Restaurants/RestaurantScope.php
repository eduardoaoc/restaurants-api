<?php

namespace App\Support\Restaurants;

use App\Models\Organization;
use App\Models\Restaurant;
use App\Models\User;

/**
 * Determines which restaurants of an organization a user may operate on
 * for restaurant-scoped operational data (Orders).
 *
 * Organization membership alone is not enough: a user holding at least one
 * organization-wide role assignment (user_roles.restaurant_id is null —
 * this is how the owner is set up; see InteractsWithTenants::assignRole())
 * may operate on every restaurant of the organization. A user whose role
 * assignments are all restaurant-scoped (operational staff, wired up by
 * CreateStaffAction alongside restaurant_users) is restricted to exactly
 * those restaurants.
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

        return $user->userRoles()
            ->where('organization_id', $organization->id)
            ->whereNotNull('restaurant_id')
            ->distinct()
            ->pluck('restaurant_id')
            ->all();
    }

    public static function canAccessRestaurant(User $user, Restaurant $restaurant): bool
    {
        $accessible = self::accessibleRestaurantIds($user, $restaurant->organization);

        return $accessible === null || in_array($restaurant->id, $accessible, true);
    }
}
