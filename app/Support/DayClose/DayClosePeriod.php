<?php

namespace App\Support\DayClose;

use App\Models\RestaurantDayClose;
use Carbon\CarbonImmutable;

/**
 * The resolved period of a Cierre Diario — see DayClosePeriodResolver.
 * [startUtc, endUtc) is the half-open range every snapshot query uses.
 */
final class DayClosePeriod
{
    public function __construct(
        public readonly CarbonImmutable $startUtc,
        public readonly CarbonImmutable $endUtc,
        public readonly string $businessDate,
        public readonly string $businessDateFrom,
        public readonly string $timezone,
        public readonly bool $firstClose,
        public readonly ?RestaurantDayClose $previousClose,
        public readonly bool $businessDateAlreadyClosed,
    ) {}

    public function isEmpty(): bool
    {
        return $this->endUtc->lessThanOrEqualTo($this->startUtc);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'business_date' => $this->businessDate,
            'business_date_from' => $this->businessDateFrom,
            'period_started_at' => DayCloseFormat::instant($this->startUtc),
            'period_ended_at' => DayCloseFormat::instant($this->endUtc),
            'timezone' => $this->timezone,
            'first_close' => $this->firstClose,
            'covers_multiple_business_days' => $this->businessDateFrom < $this->businessDate,
        ];
    }
}
