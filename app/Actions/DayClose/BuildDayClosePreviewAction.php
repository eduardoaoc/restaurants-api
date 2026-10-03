<?php

namespace App\Actions\DayClose;

use App\Models\Restaurant;
use App\Support\DayClose\DayCloseFormat;
use App\Support\DayClose\DayClosePeriodResolver;
use App\Support\DayClose\DayCloseReport;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;

/**
 * The live preview of the Cierre Diario (CARTA 9.1A): the period that a
 * close RIGHT NOW would cover and every section computed over it. Pure
 * read, nothing persisted, no lock — the close itself recomputes
 * everything under the exclusive operational lock and rejects a stale
 * period/expected cash (see CloseRestaurantDayAction).
 */
class BuildDayClosePreviewAction
{
    public function __construct(
        private readonly DayClosePeriodResolver $periodResolver,
        private readonly DayCloseReport $report,
    ) {}

    /**
     * @param  ?string  $openingFloat  only used when the opening float
     *                                 source is "required", to let the UI
     *                                 preview expected_cash
     * @return array<string, mixed>
     */
    public function execute(Restaurant $restaurant, ?string $openingFloat = null): array
    {
        $period = $this->periodResolver->resolve($restaurant, CarbonImmutable::now('UTC')->startOfSecond());
        $live = $this->report->liveSections($restaurant, $period);
        $float = $this->report->openingFloat($restaurant, $period);

        $floatCents = $float['cents'] ?? ($openingFloat !== null ? Money::decimalToCents($openingFloat) : null);
        $expectedCents = $floatCents !== null ? DayCloseReport::expectedCashCents($floatCents, $live) : null;
        $withoutFloatCents = DayCloseReport::expectedCashCents(0, $live);

        $blockers = $live['blockers'];

        if ($period->businessDateAlreadyClosed) {
            $blockers[] = ['type' => 'business_date_already_closed', 'business_date' => $period->businessDate];
        }

        if ($expectedCents !== null && $expectedCents < 0) {
            $blockers[] = ['type' => 'expected_cash_negative', 'expected_cash' => DayCloseFormat::money($expectedCents)];
        }

        return [
            'period' => $period->toArray(),
            'can_close' => $blockers === [] && ! $period->isEmpty(),
            'blockers' => $blockers,
            'warnings' => DayCloseReport::reportWarnings($live, null),
            'financial' => $live['financial'],
            'cash' => [
                'suggested_opening_float' => $float['cents'] !== null ? DayCloseFormat::money($float['cents']) : null,
                'opening_float_source' => $float['source'],
                'cash_received' => DayCloseFormat::money($live['financial_raw']['by_method_cents']['cash']),
                'cash_pay_ins' => DayCloseFormat::money($live['cash_movements_raw']['pay_ins_cents']),
                'cash_pay_outs' => DayCloseFormat::money($live['cash_movements_raw']['pay_outs_cents']),
                'expected_cash' => $expectedCents !== null ? DayCloseFormat::money($expectedCents) : null,
                'expected_cash_excluding_opening_float' => DayCloseFormat::money($withoutFloatCents),
                'cash_difference_note_threshold' => (string) $restaurant->settings->cash_difference_note_threshold,
                'movements' => $live['cash_movements_raw']['movements'],
            ],
            'operations' => $live['operations'],
            'products' => $live['products'],
            'product_availability' => $live['product_availability'],
            'feedback' => $live['feedback'],
            'delays' => $live['delays'],
        ];
    }
}
