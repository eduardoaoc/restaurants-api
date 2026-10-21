<?php

namespace App\Support\DailyOperation;

use App\Models\Restaurant;
use App\Models\RestaurantDailyOperation;
use App\Models\RestaurantDayClose;
use App\Models\TableSession;
use App\Support\Billing\PaymentsSummary;
use App\Support\DayClose\DayCloseFormat;
use App\Support\DayClose\DayClosePeriodResolver;
use App\Support\DayClose\DayCloseReport;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;

/**
 * Read model of GET /restaurants/{id}/daily-operation. Two layers:
 *
 *   - state: open/not_open, business date, who/when — for every staff
 *     member of the restaurant (they must know whether service is open);
 *   - cash (opening float, suggestion, current-operation receipts kept
 *     separate from the float) — only for $withCash (open/close holders).
 */
final class DailyOperationState
{
    public function __construct(
        private readonly DayClosePeriodResolver $periodResolver,
        private readonly DayCloseReport $report,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public static function publicState(RestaurantDailyOperation $operation): array
    {
        return [
            'id' => $operation->public_id,
            'status' => $operation->status,
            'business_date' => $operation->business_date->format('Y-m-d'),
            'timezone' => $operation->timezone,
            'opened_at' => DayCloseFormat::instant($operation->opened_at),
            'opened_by' => ['name' => $operation->opened_by_name_snapshot],
            'closed_at' => DayCloseFormat::instant($operation->closed_at),
            'closed_by' => $operation->closed_by_name_snapshot !== null ? ['name' => $operation->closed_by_name_snapshot] : null,
        ];
    }

    /**
     * The Dashboard's operational-day card (snapshot summary.sales.operation):
     * payments ("received", never "sales") of the open day's sessions; once it
     * is closed, the last closed day — clearly flagged — until the next one
     * opens at zero; "none" when the restaurant never opened a day. The
     * opening float is never part of it. At most two queries.
     *
     * @return array<string, mixed>
     */
    public static function salesCard(int $restaurantId, ?RestaurantDailyOperation $open): array
    {
        $operation = $open ?? RestaurantDailyOperation::query()
            ->where('restaurant_id', $restaurantId)
            ->where('status', RestaurantDailyOperation::STATUS_CLOSED)
            ->orderByDesc('closed_at')->orderByDesc('id')
            ->first();

        if ($operation === null) {
            return ['state' => 'none'];
        }

        $received = Money::centsToDecimal(PaymentsSummary::forDailyOperation($operation->id)['total_cents']);

        return $operation->isOpen()
            ? ['state' => 'open', 'business_date' => $operation->business_date->format('Y-m-d'), 'received' => $received]
            : ['state' => 'closed', 'business_date' => $operation->business_date->format('Y-m-d'), 'closed_at' => DayCloseFormat::instant($operation->closed_at), 'received' => $received];
    }

    /**
     * @param  array{open: bool, close: bool}  $can
     * @return array<string, mixed>
     */
    public function build(Restaurant $restaurant, array $can): array
    {
        $current = $restaurant->openDailyOperation()->first();
        $lastClose = RestaurantDayClose::query()->where('restaurant_id', $restaurant->id)
            ->orderByDesc('period_ended_at')->first(['business_date', 'closed_at']);
        $settings = $restaurant->settings;
        $now = CarbonImmutable::now('UTC')->startOfSecond();

        $state = [
            'status' => $current !== null ? 'open' : 'not_open',
            'current' => $current !== null ? self::publicState($current) : null,
            'last_close' => $lastClose !== null ? [
                'business_date' => $lastClose->business_date->format('Y-m-d'),
                'closed_at' => DayCloseFormat::instant($lastClose->closed_at),
            ] : null,
            // What an opening right now would be labelled (cutoff rule).
            'next_business_date' => $current === null ? DayClosePeriodResolver::businessDateOf($now, $settings->timezone, $settings->business_day_cutoff_time) : null,
            'can' => $can,
        ];

        if (! $can['open'] && ! $can['close']) {
            return $state;
        }

        $cash = ['currency' => $settings->currency];

        if ($current !== null) {
            $payments = PaymentsSummary::forDailyOperation($current->id);
            $cash += [
                'opening_float' => (string) $current->opening_float,
                'suggested_opening_float' => $current->suggested_opening_float !== null ? (string) $current->suggested_opening_float : null,
                'suggested_opening_float_source' => $current->suggested_opening_float_source,
                // Customer receipts of this operation only; the opening float
                // is never part of them (it is not a sale nor a payment).
                'received' => Money::centsToDecimal($payments['total_cents']),
                'cash_received' => Money::centsToDecimal($payments['cash_cents']),
                'payments_count' => $payments['payments_count'],
                'sessions_opened' => TableSession::query()->where('daily_operation_id', $current->id)->count(),
            ];
        } else {
            $suggested = $this->report->suggestedOpeningFloat($restaurant, $this->periodResolver->resolve($restaurant, $now));
            $cash += [
                'suggested_opening_float' => $suggested['cents'] !== null ? Money::centsToDecimal($suggested['cents']) : null,
                'suggested_opening_float_source' => $suggested['source'],
            ];
        }

        return $state + ['cash' => $cash];
    }
}
