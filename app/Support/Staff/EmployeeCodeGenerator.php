<?php

namespace App\Support\Staff;

use App\Models\RestaurantUser;

/**
 * Generates restaurant_users.sub_id (the per-restaurant "employee code")
 * on the backend — nobody types it any more.
 *
 * The code is derived from users.id (`E-{id}`), which is already unique and
 * assigned atomically by the database sequence, so two concurrent
 * acceptances for the same restaurant can never produce the same code: each
 * one starts from a different users.id. users.id itself stays the technical
 * identity (primary key, audit actor); sub_id is only a restaurant-scoped
 * label kept for existing contracts (staff payload, waiter snapshot).
 *
 * Legacy manual codes are never touched. If one of them happens to equal the
 * derived code at that restaurant (e.g. someone typed "E-42" years ago), a
 * numeric suffix is appended. unique(restaurant_id, sub_id) in the database
 * remains the final guarantee.
 */
class EmployeeCodeGenerator
{
    public const PREFIX = 'E-';

    public function generate(int $restaurantId, int $userId): string
    {
        $base = self::PREFIX.$userId;
        $candidate = $base;
        $suffix = 1;

        while (RestaurantUser::query()->where('restaurant_id', $restaurantId)->where('sub_id', $candidate)->exists()) {
            $candidate = $base.'-'.(++$suffix);
        }

        return $candidate;
    }
}
