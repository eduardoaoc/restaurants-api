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
 * Hardening note (Passo 3.2 follow-up): User::hasPermission() alone is
 * organization-wide — it has no idea WHICH restaurant a permission was
 * granted for. Before this trait, every one of these Policies' write
 * abilities (create/update) checked organization membership + the
 * permission slug only, never RestaurantScope — so a manager (or any
 * staff member) whose restaurant_users/user_roles rows scope them to only
 * Restaurant A of an organization could still mutate the Carta of a
 * sibling Restaurant B in the SAME organization, simply by knowing its id.
 * The read side of this got RestaurantScope during the Passo 3.2 waiter
 * fix; this trait closes the same gap on the write side, in one place,
 * so it can't reopen the next time a new ability is added.
 */
trait AuthorizesRestaurantScopedCatalog
{
    /**
     * True if the user belongs to the restaurant's organization, can
     * actually reach that specific restaurant (RestaurantScope — null for
     * an organization-wide role like owner means every restaurant, a
     * restaurant-scoped role like manager/waiter means only the ones they
     * hold a restaurant_users row for), AND holds at least one of the
     * given permission slugs in that organization.
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
            if ($user->hasPermission($slug, $organization)) {
                return true;
            }
        }

        return false;
    }
}
