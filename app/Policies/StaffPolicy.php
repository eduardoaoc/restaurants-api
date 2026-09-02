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
 * view/update/create additionally require the target's restaurant (the
 * staff member's own restaurant for view/update; the restaurant_id being
 * assigned for create) to be within the requester's RestaurantScope. This
 * is defense in depth, not the primary gate — StaffController::staffQuery()
 * / restaurantQuery() already scope the query so an out-of-scope target
 * resolves as 404 via findOrFail before the policy ever runs; the policy
 * still re-checks scope in case a caller is ever added that skips the
 * scoped query.
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
     * Viewing another staff member's performance requires view_reports
     * (reused, no new permission) and that the target's own restaurant is
     * one the requester can reach via RestaurantScope — the requester's
     * scope gates whether the target is reachable at all, it is never used
     * to widen the metrics/rating query itself.
     */
    public function viewPerformance(User $user, User $staff, Organization $organization): bool
    {
        return $this->canAccessStaff($user, $staff, $organization, 'view_reports');
    }

    /**
     * Creating/listing StaffReviews requires manage_staff_reviews (owner and
     * manager only, seeded separately from manage_users) plus the same
     * RestaurantScope reachability check as viewPerformance.
     */
    public function manageReviews(User $user, User $staff, Organization $organization): bool
    {
        return $this->canAccessStaff($user, $staff, $organization, 'manage_staff_reviews');
    }

    private function canManageUsers(User $user, Organization $organization): bool
    {
        return $user->organizations()->whereKey($organization->id)->exists()
            && $user->hasPermission('manage_users', $organization);
    }

    private function canAccessStaff(User $user, User $staff, Organization $organization, string $permission): bool
    {
        if (! $user->organizations()->whereKey($organization->id)->exists()) {
            return false;
        }

        if (! $user->hasPermission($permission, $organization)) {
            return false;
        }

        $staffRestaurant = $staff->restaurants->first();

        return $staffRestaurant !== null && RestaurantScope::canAccessRestaurant($user, $staffRestaurant);
    }
}
