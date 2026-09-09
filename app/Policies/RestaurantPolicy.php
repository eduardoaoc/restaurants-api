<?php

namespace App\Policies;

use App\Models\Organization;
use App\Models\Restaurant;
use App\Models\User;
use App\Support\Restaurants\RestaurantScope;

class RestaurantPolicy
{
    /**
     * Any member of the organization may list its restaurants.
     */
    public function viewAny(User $user, Organization $organization): bool
    {
        return $user->organizations()->whereKey($organization->id)->exists();
    }

    /**
     * Any member of the restaurant's organization may view it — but only
     * if it's within their own RestaurantScope. The controller's query is
     * already scoped (an out-of-scope restaurant never reaches this
     * Policy — it's 404 via findOrFail first), so this check is defense
     * in depth, not the primary gate; kept here anyway so the Policy is
     * never the weaker link if a future caller skips the scoped query.
     */
    public function view(User $user, Restaurant $restaurant): bool
    {
        return $user->organizations()->whereKey($restaurant->organization_id)->exists()
            && RestaurantScope::canAccessRestaurant($user, $restaurant);
    }

    /**
     * Only users holding the manage_restaurants permission may create restaurants.
     */
    public function create(User $user, Organization $organization): bool
    {
        return $user->hasPermission('manage_restaurants', $organization);
    }

    /**
     * Only users holding the manage_restaurants permission, for a
     * restaurant within their own RestaurantScope, may update it. Same
     * defense-in-depth note as view() above.
     */
    public function update(User $user, Restaurant $restaurant): bool
    {
        return $user->hasPermission('manage_restaurants', $restaurant->organization)
            && RestaurantScope::canAccessRestaurant($user, $restaurant);
    }

    /**
     * Viewing the restaurant's operational dashboard requires view_reports
     * (reused, no new permission) plus RestaurantScope reachability. The
     * route already resolves the restaurant through a RestaurantScope-
     * filtered query, so an out-of-scope restaurant is 404 before this
     * policy ever runs; the RestaurantScope check here is defense in
     * depth, same pattern as StaffPolicy::canAccessStaff.
     */
    public function viewReports(User $user, Restaurant $restaurant): bool
    {
        return $user->organizations()->whereKey($restaurant->organization_id)->exists()
            && $user->hasPermission('view_reports', $restaurant->organization)
            && RestaurantScope::canAccessRestaurant($user, $restaurant);
    }

    /**
     * Viewing/updating a restaurant's operational settings requires
     * manage_restaurants (reused, no new permission) plus RestaurantScope
     * reachability — unlike view()/update() above (organization-wide by
     * design since Bloco 1), settings are explicitly scoped: a manager
     * restricted to Restaurant A must not see or change Restaurant B's
     * settings even if they hold manage_restaurants.
     */
    public function manageSettings(User $user, Restaurant $restaurant): bool
    {
        return $user->organizations()->whereKey($restaurant->organization_id)->exists()
            && $user->hasPermission('manage_restaurants', $restaurant->organization)
            && RestaurantScope::canAccessRestaurant($user, $restaurant);
    }

    /**
     * Viewing the aggregated floor plan (Bloco 1) — same view permissions
     * as FloorPolicy/ZonePolicy: manage_tables OR close_bill, plus
     * RestaurantScope reachability.
     */
    public function viewFloorPlan(User $user, Restaurant $restaurant): bool
    {
        return $user->organizations()->whereKey($restaurant->organization_id)->exists()
            && RestaurantScope::canAccessRestaurant($user, $restaurant)
            && ($user->hasPermission('manage_tables', $restaurant->organization)
                || $user->hasPermission('close_bill', $restaurant->organization));
    }

    /**
     * Bulk-saving the floor plan layout (Bloco 1) requires manage_floor_plan
     * — same permission gate as creating/editing a Floor or Zone.
     */
    public function manageFloorPlan(User $user, Restaurant $restaurant): bool
    {
        return $user->organizations()->whereKey($restaurant->organization_id)->exists()
            && RestaurantScope::canAccessRestaurant($user, $restaurant)
            && $user->hasPermission('manage_floor_plan', $restaurant->organization);
    }
}
