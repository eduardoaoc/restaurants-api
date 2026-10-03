<?php

namespace App\Support\Operations;

use Carbon\CarbonInterface;

/**
 * Whole, non-negative elapsed seconds between two instants, for every
 * age_seconds/elapsed_seconds/active_seconds in the Operations Live
 * snapshot (Bloco 5).
 *
 * Carbon 3's diffInSeconds() defaults to a SIGNED float
 * ($now->diffInSeconds($past) returns a negative number — the opposite of
 * Carbon 2's default absolute behavior) — passing `true` for its absolute
 * parameter, and rounding to an int, is done in exactly one place so this
 * never has to be remembered correctly at each of the several call sites
 * across this feature.
 */
class ElapsedTime
{
    public static function seconds(CarbonInterface $now, CarbonInterface $since): int
    {
        return (int) round($now->diffInSeconds($since, true));
    }
}
