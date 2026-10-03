<?php

namespace App\Support\Staff;

use App\Exceptions\Staff\InvalidStaffShiftPeriodException;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Resolves the staff shifts list endpoint's optional ?from=&to= into a
 * half-open date range filtered against started_at — same shape as
 * App\Support\Audit\AuditLogPeriodResolver, kept as its own class (rather
 * than shared) to match this project's existing convention of one period
 * resolver + exception per domain (Audit/Reports/Staff Performance).
 * Omitting both simply means "no temporal filter, rely on pagination".
 */
class StaffShiftPeriodResolver
{
    private const MAX_DAYS = 366;

    /**
     * @return array{from: CarbonImmutable, toExclusive: CarbonImmutable}|null
     */
    public static function resolve(?string $from, ?string $to): ?array
    {
        if (($from === null) !== ($to === null)) {
            throw new InvalidStaffShiftPeriodException;
        }

        if ($from === null) {
            return null;
        }

        $start = self::parseDate($from);
        $end = self::parseDate($to);

        if ($end->lt($start)) {
            throw new InvalidStaffShiftPeriodException;
        }

        if ($start->diffInDays($end) + 1 > self::MAX_DAYS) {
            throw new InvalidStaffShiftPeriodException;
        }

        return [
            'from' => $start,
            'toExclusive' => $end->addDay(),
        ];
    }

    private static function parseDate(string $value): CarbonImmutable
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            throw new InvalidStaffShiftPeriodException;
        }

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);
        } catch (Throwable) {
            throw new InvalidStaffShiftPeriodException;
        }

        if (! $date || $date->format('Y-m-d') !== $value) {
            throw new InvalidStaffShiftPeriodException;
        }

        return $date->startOfDay();
    }
}
