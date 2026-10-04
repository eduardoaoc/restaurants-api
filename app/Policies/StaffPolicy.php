<?php

namespace App\Policies;

use App\Models\Organization;
use App\Models\Restaurant;
use App\Models\User;
use App\Support\Restaurants\RestaurantScope;

/**
 * Staff lists/details expose only restaurants with manage_users grants.
 * Creation requires permission at every proposed restaurant. Updating global
 * identity, organization membership or the full assignment set requires
 * permission at every existing and proposed restaurant in this organization.
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
        return $user->organizations()->whereKey($organization->id)->exists()
            && $restaurant->organization_id === $organization->id
            && RestaurantScope::canAccessRestaurant($user, $restaurant)
            && $user->hasRestaurantPermission('manage_users', $restaurant);
    }

    public function update(User $user, User $staff, Organization $organization): bool
    {
        return $this->canManageUsers($user, $organization)
            && $staff->restaurants()->where('restaurants.organization_id', $organization->id)->exists()
            && ! $staff->restaurants()->where('restaurants.organization_id', $organization->id)
                ->whereNotIn('restaurants.id', RestaurantScope::restaurantIdsWithAnyPermission($user, $organization, ['manage_users']))->exists();
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

    /**
     * Viewing a staff member's aggregate customer-feedback summary for one
     * explicit Restaurant (Passo 3.5 §13) — same shape as viewPerformance,
     * gated by view_customer_feedback (owner/manager only) instead of
     * view_reports. Still never exposes individual feedback rows — see
     * CustomerFeedbackSummaryController.
     */
    public function viewFeedbackSummary(User $user, User $staff, Organization $organization, Restaurant $restaurant): bool
    {
        return $this->canAccessStaffInRestaurant($user, $staff, $organization, $restaurant, 'view_customer_feedback');
    }

    private function canManageUsers(User $user, Organization $organization): bool
    {
        return $user->organizations()->whereKey($organization->id)->exists()
            && ($user->hasOrganizationPermission('manage_users', $organization)
                || RestaurantScope::restaurantIdsWithAnyPermission($user, $organization, ['manage_users']) !== []);
    }

    /**
     * True when at least one target restaurant is both reachable and covered
     * by the requester's grant for that same restaurant.
     */
    private function canAccessStaff(User $user, User $staff, Organization $organization, string $permission): bool
    {
        if (! $user->organizations()->whereKey($organization->id)->exists()) {
            return false;
        }

        $permitted = RestaurantScope::restaurantIdsWithAnyPermission($user, $organization, [$permission]);

        return $staff->restaurants()->where('restaurants.organization_id', $organization->id)
            ->whereIn('restaurants.id', $permitted)->exists();
    }

    /**
     * True when the requester holds $permission for the target restaurant, can
     * reach $restaurant specifically, and the target staff member holds a
     * restaurant_users row for that exact restaurant.
     */
    private function canAccessStaffInRestaurant(User $user, User $staff, Organization $organization, Restaurant $restaurant, string $permission): bool
    {
        if (! $user->organizations()->whereKey($organization->id)->exists()) {
            return false;
        }

        if (! $user->hasRestaurantPermission($permission, $restaurant)) {
            return false;
        }

        if (! RestaurantScope::canAccessRestaurant($user, $restaurant)) {
            return false;
        }

        return $restaurant->organization_id === $organization->id
            && $staff->restaurants()->whereKey($restaurant->id)->exists();
    }
}
