<?php

namespace App\Support\Restaurants;

use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Per-restaurant PostgreSQL advisory transaction lock that makes the
 * Cierre Diario snapshot a consistent point in time (CARTA 9.1A).
 *
 * Every writer whose rows feed the snapshot (payments, orders and their
 * transitions, sessions, feedback, availability, cash movements) takes it
 * SHARED; the close takes it EXCLUSIVE. Writers never block each other;
 * the close waits for every in-flight writer to commit, and new writers
 * wait for the close to commit — so no row can carry a timestamp before
 * the close's cutoff T yet become visible only after the close has read.
 *
 * The two-int4 form (namespace, restaurant_id) is used, never a string
 * hash, so two restaurants can never collide. It is a *transaction* lock
 * (released at COMMIT/ROLLBACK), so calling it outside a transaction is a
 * programming error.
 *
 * LOCK ORDERING (deadlock rule): this lock is always the FIRST lock a
 * transaction takes — before any SELECT ... FOR UPDATE. A writer that
 * already held a row lock and then waited here could deadlock with the
 * close (which holds this lock exclusively and reads, but never row-locks,
 * those rows). Within one backend, Postgres never conflicts a session
 * with its own advisory locks, so nested writers are safe.
 */
final class RestaurantOperationalLock
{
    /**
     * Fixed namespace for the first int4 key. Arbitrary but stable — never
     * reuse it for another advisory lock purpose.
     */
    public const NAMESPACE = 9101;

    public static function shared(int $restaurantId): void
    {
        self::assertInTransaction();

        DB::select('SELECT pg_advisory_xact_lock_shared(?, ?)', [self::NAMESPACE, $restaurantId]);
    }

    public static function exclusive(int $restaurantId): void
    {
        self::assertInTransaction();

        DB::select('SELECT pg_advisory_xact_lock(?, ?)', [self::NAMESPACE, $restaurantId]);
    }

    private static function assertInTransaction(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('RestaurantOperationalLock must be acquired inside a database transaction.');
        }
    }
}
