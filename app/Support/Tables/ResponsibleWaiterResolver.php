<?php

namespace App\Support\Tables;

use App\Models\OrganizationUser;
use App\Models\TableSession;
use App\Models\User;

/**
 * Who should get a directed, per-user operational signal about a table
 * session (CARTA 7.1A: "order ready" attention). The single source of
 * truth is TableSession.assigned_waiter_user_id — the session's current
 * responsible waiter, which follows table transfers (same session) and is
 * changed only by Assign/UnassignWaiterAction. Never "whoever last
 * touched the order", never a guess.
 *
 * Returns null — no directed signal at all — when there is no assigned
 * waiter, or the assigned user can no longer legitimately receive it:
 * suspended account, inactive membership in the restaurant's
 * organization, or no longer a member of that restaurant. In that case
 * callers rely on the restaurant-wide signals only. Like
 * CallResponsibleWaiterAction, an active StaffShift is NOT required.
 */
class ResponsibleWaiterResolver
{
    public static function recipientFor(TableSession $session): ?User
    {
        if ($session->assigned_waiter_user_id === null) {
            return null;
        }

        $waiter = User::query()->whereKey($session->assigned_waiter_user_id)->first();

        if (! $waiter || $waiter->isSuspended()) {
            return null;
        }

        $restaurant = $session->restaurant;

        $activeInOrganization = $waiter->organizations()
            ->wherePivot('status', OrganizationUser::STATUS_ACTIVE)
            ->whereKey($restaurant->organization_id)
            ->exists();

        if (! $activeInOrganization || ! $waiter->restaurants()->whereKey($restaurant->id)->exists()) {
            return null;
        }

        return $waiter;
    }
}
