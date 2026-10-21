<?php

namespace App\Support\Tables;

use App\Support\Restaurants\RestaurantOperationalLock;
use Illuminate\Support\Facades\DB;

/** Lock order: operational lock, assignment lock, table/session, candidate.
 * Serializes demand reads AND assignment writes within one restaurant.
 * Acquire before any row locks, including in callers of nested actions.
 */
final class WaiterAssignmentLock
{
    public static function acquire(int $restaurantId): void
    {
        RestaurantOperationalLock::shared($restaurantId);
        DB::select('SELECT pg_advisory_xact_lock(?, ?)', [9102, $restaurantId]);
    }
}
