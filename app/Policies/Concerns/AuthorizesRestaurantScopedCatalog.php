<?php

namespace App\Policies\Concerns;

use App\Models\Restaurant;
use App\Models\User;
use App\Support\Restaurants\RestaurantScope;

/**
 * Shared authorization shape for every Carta resource scoped to a single
 * Restaurant (Category, RestaurantProduct, ModifierGroup, ModifierOption,
 * Menu): organization membership, RestaurantScope reachability, and the
 * permission slug itself.
 *
 * Permission grants must apply to this restaurant; reachability alone is
 * insufficient when a user holds different roles at different restaurants.
 */
trait AuthorizesRestaurantScopedCatalog
{
    /**
     * True if the user belongs to the restaurant's organization, can
     * actually reach that specific restaurant (RestaurantScope — null for
     * an organization-wide role like owner means every restaurant, a
     * restaurant-scoped role like manager/waiter means only the ones they
     * hold a restaurant_users row for), AND holds at least one of the
     * given permission slugs for this restaurant.
     *
     * @param  array<int, string>  $anyOfPermissionSlugs
     */
    private function userCanAccessRestaurantWithAnyPermission(User $user, Restaurant $restaurant, array $anyOfPermissionSlugs): bool
    {
        $organization = $restaurant->organization;

        if (! $user->organizations()->whereKey($organization->id)->exists()) {
            return false;
        }

        if (! RestaurantScope::canAccessRestaurant($user, $restaurant)) {
            return false;
        }

        foreach ($anyOfPermissionSlugs as $slug) {
            if ($user->hasRestaurantPermission($slug, $restaurant)) {
                return true;
            }
        }

        return false;
    }
}
