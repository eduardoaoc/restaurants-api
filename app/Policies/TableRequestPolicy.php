<?php

namespace App\Policies;

use App\Models\Organization;
use App\Models\TableRequest;
use App\Models\User;
use App\Support\Restaurants\RestaurantScope;

/**
 * Authorizes operational access to table requests (call_waiter/
 * request_bill). Same shape as OrderPolicy: organization membership +
 * permission, plus restaurant scope (RestaurantScope) — organization
 * membership alone is not enough for operational staff. Controllers
 * additionally scope their queries by RestaurantScope so an out-of-scope
 * request resolves as "not found" (404) rather than merely "forbidden"
 * (403).
 */
class TableRequestPolicy
{
    private const PERMISSION = 'handle_table_requests';

    public function viewAny(User $user, Organization $organization): bool
    {
        return $this->belongsTo($user, $organization) && $user->hasPermission(self::PERMISSION, $organization);
    }

    public function view(User $user, TableRequest $tableRequest): bool
    {
        return $this->hasPermissionInRestaurantScope($user, $tableRequest);
    }

    public function acknowledge(User $user, TableRequest $tableRequest): bool
    {
        return $this->hasPermissionInRestaurantScope($user, $tableRequest);
    }

    public function complete(User $user, TableRequest $tableRequest): bool
    {
        return $this->hasPermissionInRestaurantScope($user, $tableRequest);
    }

    public function cancel(User $user, TableRequest $tableRequest): bool
    {
        return $this->hasPermissionInRestaurantScope($user, $tableRequest);
    }

    private function belongsTo(User $user, Organization $organization): bool
    {
        return $user->organizations()->whereKey($organization->id)->exists();
    }

    private function hasPermissionInRestaurantScope(User $user, TableRequest $tableRequest): bool
    {
        $organization = $tableRequest->restaurant->organization;

        return $this->belongsTo($user, $organization)
            && $user->hasPermission(self::PERMISSION, $organization)
            && RestaurantScope::canAccessRestaurant($user, $tableRequest->restaurant);
    }
}
