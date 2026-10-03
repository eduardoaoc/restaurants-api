<?php

namespace App\Support\Tables;

use App\Models\Organization;
use App\Models\Restaurant;
use App\Models\User;
use App\Models\UserRole;

/**
 * Centralizes who may be assigned as the responsible waiter of a table
 * session (Bloco 2) — the single source of truth, never a scattered
 * `if ($role === 'waiter')` in a Controller/Request/Action. A candidate is
 * eligible only when ALL of the following hold:
 *
 *   - they belong to the same Organization as the session's Restaurant;
 *   - their account is active (not suspended — Bloco 0);
 *   - they hold a restaurant_users row for that exact Restaurant — the
 *     sole authority for restaurant membership (see RestaurantScope);
 *   - they hold the `waiter` role in user_roles specifically scoped to
 *     that Restaurant (roles are assigned per-restaurant — see
 *     CreateStaffAction/UpdateStaffAction).
 *
 * Deliberately does NOT use RestaurantScope::accessibleRestaurantIds()/
 * canAccessRestaurant() here: those answer "which restaurants may this
 * user OPERATE ON" (including "every restaurant" for an organization-wide
 * role holder such as Owner/Manager), which is a different question from
 * "does this specific candidate hold a restaurant_users row for this
 * specific Restaurant". Eligibility to BE the waiter requires the latter,
 * explicit membership — an Owner with no restaurant_users row anywhere
 * must never be selectable as a waiter just because RestaurantScope would
 * let them reach the restaurant to assign one.
 */
class WaiterAssignmentEligibility
{
    /**
     * The operational role a candidate must hold at the Restaurant to be
     * eligible. Centralized here so the day this needs to broaden (e.g. a
     * permission-based check instead of a hardcoded role slug) there is
     * exactly one place to change — see the Bloco 2 report.
     */
    public const ELIGIBLE_ROLE_SLUG = 'waiter';

    /**
     * Returns null when $candidate is eligible, or a human-readable reason
     * otherwise. A reason (rather than a bool) lets callers surface a
     * precise 422 message without re-deriving which check failed.
     */
    public static function ineligibilityReason(User $candidate, Organization $organization, Restaurant $restaurant): ?string
    {
        if (! $candidate->organizations()->whereKey($organization->id)->exists()) {
            return 'The selected user does not belong to this organization.';
        }

        if ($candidate->isSuspended()) {
            return 'The selected user is suspended and cannot be assigned.';
        }

        if (! $candidate->restaurants()->whereKey($restaurant->id)->exists()) {
            return 'The selected user is not a member of this restaurant.';
        }

        $hasWaiterRole = UserRole::query()
            ->where('user_id', $candidate->id)
            ->where('organization_id', $organization->id)
            ->where('restaurant_id', $restaurant->id)
            ->whereHas('role', fn ($query) => $query->where('slug', self::ELIGIBLE_ROLE_SLUG))
            ->exists();

        if (! $hasWaiterRole) {
            return 'The selected user does not have the waiter role at this restaurant.';
        }

        return null;
    }
}
