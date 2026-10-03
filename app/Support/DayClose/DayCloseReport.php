<?php

namespace App\Support\DayClose;

use App\Models\PaymentRecord;
use App\Models\Restaurant;
use App\Support\Money\Money;

/**
 * Assembles the Cierre Diario (CARTA 9.1A) from DayCloseSnapshotBuilder's
 * sections: the live preview and, with the cash count and closing data,
 * the versioned immutable `report` (schema REPORT_SCHEMA_VERSION).
 *
 * Report contract (v1) — every section always present:
 *   schema_version, summary, financial, cash, operations, products,
 *   product_availability, feedback, delays, warnings, closing.
 */
class DayCloseReport
{
    public const REPORT_SCHEMA_VERSION = 1;

    public const OPENING_FLOAT_SOURCE_PREVIOUS_CLOSE = 'previous_close';

    public const OPENING_FLOAT_SOURCE_RESTAURANT_DEFAULT = 'restaurant_default';

    public const OPENING_FLOAT_SOURCE_REQUIRED = 'required';

    public function __construct(private readonly DayCloseSnapshotBuilder $builder) {}

    /**
     * Every live section for the period, plus blockers and the
     * operational warnings. Pure reads.
     *
     * @return array<string, mixed>
     */
    public function liveSections(Restaurant $restaurant, DayClosePeriod $period): array
    {
        $start = $period->startUtc;
        $end = $period->endUtc;

        $financial = $this->builder->financial($restaurant, $start, $end);
        $cashMovements = $this->builder->cashMovements($restaurant, $start, $end);
        $availability = $this->builder->productAvailability($restaurant, $start, $end);

        return [
            'financial_raw' => $financial,
            'cash_movements_raw' => $cashMovements,
            'financial' => [
                'currency' => $restaurant->settings->currency,
                'total_received' => DayCloseFormat::money($financial['total_received_cents']),
                'by_method' => collect(PaymentRecord::METHODS)
                    ->mapWithKeys(fn (string $method) => [$method => DayCloseFormat::money($financial['by_method_cents'][$method])])
                    ->all(),
                'payments_count' => $financial['payments_count'],
                'sessions_with_payments' => $financial['sessions_with_payments'],
                'average_ticket' => DayCloseFormat::money($financial['average_ticket_cents']),
            ],
            'operations' => $this->builder->operations($restaurant, $start, $end, $period->timezone),
            'products' => ['top' => $this->builder->topProducts($restaurant, $start, $end)],
            'product_availability' => $availability,
            'feedback' => $this->builder->feedback($restaurant, $start, $end),
            'delays' => $this->builder->delays($restaurant, $start, $end),
            'blockers' => $this->builder->activeSessionBlockers($restaurant),
            'operational_warnings' => $this->builder->operationalWarnings($restaurant),
        ];
    }

    /**
     * Opening float precedence: the previous close's cash_left_for_next_day
     * when not null, else RestaurantSettings.default_opening_float, else
     * the closer must state it.
     *
     * @return array{cents: ?int, source: string}
     */
    public function openingFloat(Restaurant $restaurant, DayClosePeriod $period): array
    {
        $previousLeft = $period->previousClose?->cash_left_for_next_day;

        if ($previousLeft !== null) {
            return ['cents' => Money::decimalToCents((string) $previousLeft), 'source' => self::OPENING_FLOAT_SOURCE_PREVIOUS_CLOSE];
        }

        $default = $restaurant->settings->default_opening_float;

        if ($default !== null) {
            return ['cents' => Money::decimalToCents((string) $default), 'source' => self::OPENING_FLOAT_SOURCE_RESTAURANT_DEFAULT];
        }

        return ['cents' => null, 'source' => self::OPENING_FLOAT_SOURCE_REQUIRED];
    }

    /**
     * expected_cash = opening_float + cash payments + pay-ins - pay-outs.
     * Card/other never enter the drawer.
     *
     * @param  array<string, mixed>  $live
     */
    public static function expectedCashCents(int $openingFloatCents, array $live): int
    {
        return $openingFloatCents
            + $live['financial_raw']['by_method_cents'][PaymentRecord::METHOD_CASH]
            + $live['cash_movements_raw']['pay_ins_cents']
            - $live['cash_movements_raw']['pay_outs_cents'];
    }

    /**
     * Report-derived warnings (never blockers).
     *
     * @param  array<string, mixed>  $live
     * @return array<int, array<string, mixed>>
     */
    public static function reportWarnings(array $live, ?int $cashDifferenceCents): array
    {
        $warnings = $live['operational_warnings'];

        $stillUnavailable = collect($live['product_availability']['items'])->where('state_at_close', 'unavailable')->pluck('restaurant_product_id')->unique()->count();

        if ($stillUnavailable > 0) {
            $warnings[] = ['type' => 'products_still_unavailable', 'count' => $stillUnavailable];
        }

        if ($live['feedback']['critical_count'] > 0) {
            $warnings[] = ['type' => 'critical_feedback', 'count' => $live['feedback']['critical_count']];
        }

        if ($live['delays']['total_count'] > 0) {
            $warnings[] = ['type' => 'severe_delays', 'count' => $live['delays']['total_count']];
        }

        if ($cashDifferenceCents !== null && $cashDifferenceCents !== 0) {
            $warnings[] = ['type' => 'cash_difference', 'amount' => DayCloseFormat::money($cashDifferenceCents)];
        }

        return $warnings;
    }

    /**
     * any cash difference, critical feedback, severe delay, product
     * marked unavailable, or a closing note. Low-dimension ("atención")
     * feedback is reported but is not an incident.
     *
     * @param  array<string, mixed>  $live
     */
    public static function hasIncidents(array $live, int $cashDifferenceCents, ?string $notes): bool
    {
        return $cashDifferenceCents !== 0
            || $live['feedback']['critical_count'] > 0
            || $live['delays']['total_count'] > 0
            || $live['product_availability']['count'] > 0
            || $notes !== null;
    }

    /**
     * The immutable report persisted on the close.
     *
     * @param  array<string, mixed>  $live
     * @param  array<string, mixed>  $cash
     * @param  array<string, mixed>  $closing
     * @return array<string, mixed>
     */
    public static function report(Restaurant $restaurant, DayClosePeriod $period, array $live, array $cash, array $closing, bool $hasIncidents, int $cashDifferenceCents): array
    {
        return [
            'schema_version' => self::REPORT_SCHEMA_VERSION,
            'summary' => [
                'restaurant' => ['id' => $restaurant->id, 'name' => $restaurant->name],
                ...$period->toArray(),
                'currency' => $live['financial']['currency'],
                'total_received' => $live['financial']['total_received'],
                'orders_valid' => $live['operations']['orders']['valid'],
                'guests' => $live['operations']['guests'],
                'average_ticket' => $live['financial']['average_ticket'],
                'cash_difference' => $cash['cash_difference'],
                'top_product' => $live['products']['top'][0] ?? null,
                'critical_feedback_count' => $live['feedback']['critical_count'],
                'delays_count' => $live['delays']['total_count'],
                'unavailable_products_count' => $live['product_availability']['count'],
                'has_incidents' => $hasIncidents,
            ],
            'financial' => $live['financial'],
            'cash' => $cash,
            'operations' => $live['operations'],
            'products' => $live['products'],
            'product_availability' => $live['product_availability'],
            'feedback' => $live['feedback'],
            'delays' => $live['delays'],
            'warnings' => self::reportWarnings($live, $cashDifferenceCents),
            'closing' => $closing,
        ];
    }
}
