<?php

namespace App\Policies;

use App\Models\Organization;
use App\Models\Restaurant;
use App\Models\User;
use App\Support\Restaurants\RestaurantScope;

/**
 * Authorizes management of operational staff (manager/waiter/kitchen/cashier)
 * within the active organization. Every ability requires the acting user to
 * belong to the organization and to hold the manage_users permission there —
 * there is no role-name shortcut; owner and manager only pass because their
 * seeded role grants manage_users.
 *
 * view/update additionally require AT LEAST ONE of the target's restaurants
 * (Bloco 18: a staff member may have 1..N) to be within the requester's
 * RestaurantScope — matching StaffController::staffQuery()'s
 * whereHas('restaurants', ...) semantics exactly: a requester who can reach
 * any one of the target's restaurants can find them, so the policy must
 * agree, not silently disagree by only checking one arbitrarily-picked
 * restaurant. This is defense in depth, not the primary gate —
 * staffQuery()/restaurantQuery() already scope the query so a target with
 * zero reachable restaurants resolves as 404 via findOrFail before the
 * policy ever runs.
 */
class StaffPolicy
{
    public function viewAny(User $user, Organization $organization): bool
    {
        return $this->canManageUsers($user, $organization);
    }

    public function view(User $user, User $staff, Organization $organization): bool
    {
        return $this->canAccessStaff($user, $staff, $organization, 'manage_users');
    }

    public function create(User $user, Organization $organization, Restaurant $restaurant): bool
    {
        return $this->canManageUsers($user, $organization)
            && RestaurantScope::canAccessRestaurant($user, $restaurant);
    }

    public function update(User $user, User $staff, Organization $organization): bool
    {
        return $this->canAccessStaff($user, $staff, $organization, 'manage_users');
    }

    /**
     * Viewing a staff member's performance for one explicit Restaurant
     * (Bloco 18: GET /restaurants/{restaurant}/staff/{staff}/performance)
     * requires view_reports (reused, no new permission), that the
     * requester can reach that exact Restaurant, and that the target staff
     * member actually holds a restaurant_users row there — a staff member
     * assigned to A+B queried through Restaurant C (which they have no
     * link to at all) is not "their performance elsewhere", it is not
     * found.
     */
    public function viewPerformance(User $user, User $staff, Organization $organization, Restaurant $restaurant): bool
    {
        return $this->canAccessStaffInRestaurant($user, $staff, $organization, $restaurant, 'view_reports');
    }

    /**
     * Creating/listing StaffReviews for one explicit Restaurant requires
     * manage_staff_reviews (owner and manager only, seeded separately from
     * manage_users) plus the same per-Restaurant reachability check as
     * viewPerformance.
     */
    public function manageReviews(User $user, User $staff, Organization $organization, Restaurant $restaurant): bool
    {
        return $this->canAccessStaffInRestaurant($user, $staff, $organization, $restaurant, 'manage_staff_reviews');
    }

    private function canManageUsers(User $user, Organization $organization): bool
    {
        return $user->organizations()->whereKey($organization->id)->exists()
            && $user->hasPermission('manage_users', $organization);
    }

    /**
     * True when the requester holds $permission in the organization AND
     * can reach at least one of the target staff member's restaurants.
     */
    private function canAccessStaff(User $user, User $staff, Organization $organization, string $permission): bool
    {
        if (! $user->organizations()->whereKey($organization->id)->exists()) {
            return false;
        }

        if (! $user->hasPermission($permission, $organization)) {
            return false;
        }

        $staffRestaurantIds = $staff->restaurants->pluck('id');

        if ($staffRestaurantIds->isEmpty()) {
            return false;
        }

        $accessible = RestaurantScope::accessibleRestaurantIds($user, $organization);

        return $accessible === null || $staffRestaurantIds->intersect($accessible)->isNotEmpty();
    }

    /**
     * True when the requester holds $permission in the organization, can
     * reach $restaurant specifically, and the target staff member holds a
     * restaurant_users row for that exact restaurant.
     */
    private function canAccessStaffInRestaurant(User $user, User $staff, Organization $organization, Restaurant $restaurant, string $permission): bool
    {
        if (! $user->organizations()->whereKey($organization->id)->exists()) {
            return false;
        }

        if (! $user->hasPermission($permission, $organization)) {
            return false;
        }

        if (! RestaurantScope::canAccessRestaurant($user, $restaurant)) {
            return false;
        }

        return $staff->restaurants->contains('id', $restaurant->id);
    }
}
