<?php

namespace App\Support\Staff;

use App\Models\Organization;
use App\Models\Restaurant;
use App\Models\User;
use App\Models\UserRole;

/**
 * Centralizes who may hold a StaffShift at a Restaurant (Bloco 3) — the
 * single source of truth, never a scattered role/membership check across
 * Controller/Request/Action. Mirrors WaiterAssignmentEligibility (Bloco 2)
 * exactly, with a broader eligible-role set. A candidate is eligible only
 * when ALL of the following hold:
 *
 *   - they belong to the same Organization as the Restaurant;
 *   - their account is active (not suspended — Bloco 0);
 *   - they hold a restaurant_users row for that exact Restaurant — the
 *     sole authority for restaurant membership (see RestaurantScope);
 *   - they hold one of the operational roles below in user_roles,
 *     specifically scoped to that Restaurant (roles are assigned
 *     per-restaurant — see CreateStaffAction/UpdateStaffAction).
 *
 * Owner is deliberately NOT special-cased out: a plain Owner has no
 * restaurant_users row anywhere (their role is organization-wide, with
 * user_roles.restaurant_id null — see RestaurantScope) and fails the
 * membership check like anyone else without one. An Owner who ALSO holds
 * an explicit operational restaurant_users + user_roles assignment at a
 * Restaurant (a deliberate, separate grant — not how CreateStaffAction
 * sets up ownership) is eligible on that same basis, exactly as Bloco 3's
 * spec intends: "owner can have a shift if they also hold explicit
 * operational membership there".
 */
class StaffShiftEligibility
{
    /**
     * @var array<int, string>
     */
    public const ELIGIBLE_ROLE_SLUGS = ['manager', 'waiter', 'kitchen', 'cashier'];

    /**
     * Returns null when $candidate is eligible, or a human-readable reason
     * otherwise.
     */
    public static function ineligibilityReason(User $candidate, Organization $organization, Restaurant $restaurant): ?string
    {
        if (! $candidate->organizations()->whereKey($organization->id)->exists()) {
            return 'The selected user does not belong to this organization.';
        }

        if ($candidate->isSuspended()) {
            return 'The selected user is suspended and cannot start a shift.';
        }

        if (! $candidate->restaurants()->whereKey($restaurant->id)->exists()) {
            return 'The selected user is not a member of this restaurant.';
        }

        $hasEligibleRole = UserRole::query()
            ->where('user_id', $candidate->id)
            ->where('organization_id', $organization->id)
            ->where('restaurant_id', $restaurant->id)
            ->whereHas('role', fn ($query) => $query->whereIn('slug', self::ELIGIBLE_ROLE_SLUGS))
            ->exists();

        if (! $hasEligibleRole) {
            return 'The selected user does not have an operational role at this restaurant.';
        }

        return null;
    }
}
