<?php

namespace App\Support\Audit;

use App\Exceptions\Audit\InvalidAuditPeriodException;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Resolves the audit log endpoint's optional ?from=&to= into a half-open
 * date range — same half-open/366-day-max contract as
 * Staff\PerformancePeriodResolver (Bloco 15), but with no default: unlike
 * staff performance, omitting both here simply means "no temporal filter,
 * rely on pagination for the most recent entries" rather than defaulting
 * to the current month.
 */
class AuditLogPeriodResolver
{
    private const MAX_DAYS = 366;

    /**
     * @return array{from: CarbonImmutable, toExclusive: CarbonImmutable}|null
     */
    public static function resolve(?string $from, ?string $to): ?array
    {
        if (($from === null) !== ($to === null)) {
            throw new InvalidAuditPeriodException;
        }

        if ($from === null) {
            return null;
        }

        $start = self::parseDate($from);
        $end = self::parseDate($to);

        if ($end->lt($start)) {
            throw new InvalidAuditPeriodException;
        }

        if ($start->diffInDays($end) + 1 > self::MAX_DAYS) {
            throw new InvalidAuditPeriodException;
        }

        return [
            'from' => $start,
            'toExclusive' => $end->addDay(),
        ];
    }

    private static function parseDate(string $value): CarbonImmutable
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            throw new InvalidAuditPeriodException;
        }

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);
        } catch (Throwable) {
            throw new InvalidAuditPeriodException;
        }

        if (! $date || $date->format('Y-m-d') !== $value) {
            throw new InvalidAuditPeriodException;
        }

        return $date->startOfDay();
    }
}
