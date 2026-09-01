<?php

namespace App\Policies;

use App\Models\TableSession;
use App\Models\User;

/**
 * Authorizes closing a table session. Owner/manager/waiter close via
 * manage_tables; cashier closes via close_bill. Kitchen has neither.
 */
class TableSessionPolicy
{
    public function close(User $user, TableSession $session): bool
    {
        $organization = $session->restaurant->organization;

        return $user->organizations()->whereKey($organization->id)->exists()
            && ($user->hasPermission('manage_tables', $organization) || $user->hasPermission('close_bill', $organization));
    }
}
