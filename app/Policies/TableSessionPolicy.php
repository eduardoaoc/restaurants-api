<?php

namespace App\Policies;

use App\Models\Organization;
use App\Models\TableSession;
use App\Models\User;
use App\Support\Restaurants\RestaurantScope;

/**
 * Authorizes operations on a table session: closing it, viewing its bill,
 * and recording payments against it.
 *
 * close(): owner/manager/waiter via manage_tables; cashier via close_bill.
 * viewBill(): anyone who could plausibly need to see the bill — the same
 * OR set as close(), plus record_payments (a cashier or waiter about to
 * take payment needs to see the balance first).
 * recordPayment(): record_payments specifically.
 * viewReceipt(): record_payments OR close_bill (Bloco 14) — no dedicated
 * permission; the right to print the bill follows from the right to
 * handle payment/closing it, not a separate grant.
 *
 * Every ability also requires RestaurantScope::canAccessRestaurant() —
 * organization membership alone is not enough for operational staff. This
 * was previously missing from close() (a gap predating RestaurantScope's
 * introduction); fixed here since Bloco 13 requires it explicitly.
 */
class TableSessionPolicy
{
    public function close(User $user, TableSession $session): bool
    {
        return $this->hasAnyPermissionInRestaurantScope($user, $session, ['manage_tables', 'close_bill']);
    }

    public function viewBill(User $user, TableSession $session): bool
    {
        return $this->hasAnyPermissionInRestaurantScope($user, $session, ['manage_tables', 'close_bill', 'record_payments']);
    }

    public function recordPayment(User $user, TableSession $session): bool
    {
        return $this->hasAnyPermissionInRestaurantScope($user, $session, ['record_payments']);
    }

    public function viewReceipt(User $user, TableSession $session): bool
    {
        return $this->hasAnyPermissionInRestaurantScope($user, $session, ['record_payments', 'close_bill']);
    }

    private function belongsTo(User $user, Organization $organization): bool
    {
        return $user->organizations()->whereKey($organization->id)->exists();
    }

    /**
     * @param  array<int, string>  $permissions
     */
    private function hasAnyPermissionInRestaurantScope(User $user, TableSession $session, array $permissions): bool
    {
        $organization = $session->restaurant->organization;

        if (! $this->belongsTo($user, $organization) || ! RestaurantScope::canAccessRestaurant($user, $session->restaurant)) {
            return false;
        }

        foreach ($permissions as $permission) {
            if ($user->hasPermission($permission, $organization)) {
                return true;
            }
        }

        return false;
    }
}
