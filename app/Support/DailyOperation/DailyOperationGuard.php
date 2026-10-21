<?php

namespace App\Support\DailyOperation;

use App\Exceptions\DailyOperation\DailyOperationException;
use App\Models\RestaurantDailyOperation;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * The single backend gate for operational writes that start new service:
 * opening a table session (staff or QR), creating an order (staff or QR)
 * and public table requests. Reads, history, post-visit, catalog and
 * availability management never pass through here.
 *
 * Must run AFTER RestaurantOperationalLock::shared() in the same
 * transaction: the Cierre Diario closes the operation while holding the
 * lock exclusively, so a writer waiting on the shared lock reads the
 * committed (closed) state here and is refused — nothing escapes a close
 * by racing it. Opening also takes the lock exclusively.
 */
final class DailyOperationGuard
{
    public static function requireOpen(int $restaurantId): RestaurantDailyOperation
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('DailyOperationGuard must run inside the operational transaction.');
        }

        $operation = RestaurantDailyOperation::query()
            ->where('restaurant_id', $restaurantId)
            ->where('status', RestaurantDailyOperation::STATUS_OPEN)
            ->first();

        if ($operation === null) {
            throw DailyOperationException::notOpen();
        }

        return $operation;
    }

    public static function isOpen(int $restaurantId): bool
    {
        return RestaurantDailyOperation::query()
            ->where('restaurant_id', $restaurantId)
            ->where('status', RestaurantDailyOperation::STATUS_OPEN)
            ->exists();
    }
}
