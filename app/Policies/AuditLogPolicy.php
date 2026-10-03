<?php

namespace App\Policies;

use App\Models\Organization;
use App\Models\User;

/**
 * Authorizes reading the audit log. There is no per-resource "view" ability
 * — the collection endpoint is the only surface (see report), and
 * RestaurantScope is applied directly in AuditLogController's query, not
 * here: an out-of-scope restaurant_id filter is rejected as 404 before the
 * query even runs, which the query itself (not this policy) enforces.
 */
class AuditLogPolicy
{
    public function viewAny(User $user, Organization $organization): bool
    {
        return $user->organizations()->whereKey($organization->id)->exists()
            && $user->hasPermission('view_audit', $organization);
    }
}
