<?php

namespace App\Policies;

use App\Models\Organization;
use App\Models\Restaurant;
use App\Models\StaffShift;
use App\Models\User;
use App\Support\Restaurants\RestaurantScope;

/**
 * Authorizes StaffShift operations (Bloco 3). Deliberately separates two
 * questions that must never be conflated:
 *
 *   - Authorization (THIS policy): who may attempt to start/end a shift —
 *     the acting user themself (self start/end, no permission required),
 *     or anyone holding manage_staff_shifts (owner/manager by default).
 *   - Eligibility (StaffShiftEligibility): whether the TARGET user is
 *     actually a valid candidate to hold a shift at all (membership,
 *     status, role) — checked inside the Action, independent of who is
 *     asking.
 *
 * Every ability also requires RestaurantScope::canAccessRestaurant() —
 * matching TableSessionPolicy/StaffPolicy. In practice the controller's
 * own scoped query already turns "outside RestaurantScope" into a 404
 * before this policy ever runs; the check here is defense in depth.
 */
class StaffShiftPolicy
{
    public function viewAny(User $user, Organization $organization): bool
    {
        return $this->belongsTo($user, $organization) && $user->hasPermission('manage_staff_shifts', $organization);
    }

    public function start(User $user, User $candidate, Restaurant $restaurant): bool
    {
        $organization = $restaurant->organization;

        if (! $this->belongsTo($user, $organization) || ! RestaurantScope::canAccessRestaurant($user, $restaurant)) {
            return false;
        }

        if ($user->is($candidate)) {
            return true;
        }

        return $user->hasPermission('manage_staff_shifts', $organization);
    }

    public function end(User $user, StaffShift $shift): bool
    {
        $organization = $shift->restaurant->organization;

        if (! $this->belongsTo($user, $organization) || ! RestaurantScope::canAccessRestaurant($user, $shift->restaurant)) {
            return false;
        }

        if ($user->id === $shift->user_id) {
            return true;
        }

        return $user->hasPermission('manage_staff_shifts', $organization);
    }

    private function belongsTo(User $user, Organization $organization): bool
    {
        return $user->organizations()->whereKey($organization->id)->exists();
    }
}
