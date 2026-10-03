<?php

namespace App\Policies;

use App\Models\Organization;
use App\Models\TableSession;
use App\Models\User;
use App\Models\WaiterCall;
use App\Support\Restaurants\RestaurantScope;

/**
 * Authorizes "call responsible waiter" (Bloco 4).
 *
 * create(): reuses assign_waiters (Bloco 2) rather than a dedicated
 * permission — calling the currently assigned waiter is a lighter-weight
 * action on the same assignment relationship that permission already
 * governs (assign/reassign/unassign), and owner/manager-only is exactly
 * the right default here too. See the Bloco 4 report.
 * acknowledge(): the CALLED waiter themself (self, no permission needed —
 * "ok, I saw it, I'm coming"), or anyone holding assign_waiters. Mirrors
 * StaffShiftPolicy's self-OR-permission split (Bloco 3).
 */
class WaiterCallPolicy
{
    private const PERMISSION = 'assign_waiters';

    public function create(User $user, TableSession $session): bool
    {
        $organization = $session->restaurant->organization;

        return $this->belongsTo($user, $organization)
            && $user->hasPermission(self::PERMISSION, $organization)
            && RestaurantScope::canAccessRestaurant($user, $session->restaurant);
    }

    public function acknowledge(User $user, WaiterCall $call): bool
    {
        $organization = $call->restaurant->organization;

        if (! $this->belongsTo($user, $organization) || ! RestaurantScope::canAccessRestaurant($user, $call->restaurant)) {
            return false;
        }

        if ($user->id === $call->waiter_user_id) {
            return true;
        }

        return $user->hasPermission(self::PERMISSION, $organization);
    }

    private function belongsTo(User $user, Organization $organization): bool
    {
        return $user->organizations()->whereKey($organization->id)->exists();
    }
}
