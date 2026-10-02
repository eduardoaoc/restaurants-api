<?php

namespace App\Actions\DayClose;

use App\Exceptions\DayClose\DayCloseException;
use App\Models\AuditLog;
use App\Models\Restaurant;
use App\Models\RestaurantDayClose;
use App\Models\User;
use App\Support\Activity\ActivityActor;
use App\Support\Activity\RestaurantActivityRecorder;
use App\Support\Activity\RestaurantActivityType;
use App\Support\Audit\AuditLogger;
use App\Support\DayClose\DayCloseFormat;
use App\Support\DayClose\DayClosePeriod;
use App\Support\DayClose\DayClosePeriodResolver;
use App\Support\DayClose\DayCloseReport;
use App\Support\Money\Money;
use App\Support\Restaurants\RestaurantOperationalLock;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Completes the Cierre Diario (CARTA 9.1A) — ONE transaction:
 *
 *   1. EXCLUSIVE RestaurantOperationalLock: waits for every in-flight
 *      writer (payments, orders, sessions, feedback, availability, cash
 *      movements) to commit and holds back new ones, so every query
 *      below reads the same committed state;
 *   2. idempotent replay (same key + same payload => the same close);
 *   3. T captured AFTER the lock — the period's end;
 *   4. period resolved and checked against the client's
 *      period_started_at (409 PERIOD_CHANGED) and business date
 *      (409 BUSINESS_DATE_ALREADY_CLOSED);
 *   5. blockers: ANY active table session (422 CLOSE_BLOCKED). There is
 *      no force close: no user or capability can override a blocker;
 *   6. every section recomputed — never trusted from the preview;
 *   7. opening float + expected cash, checked against
 *      expected_cash_seen (409 CASH_EXPECTATION_CHANGED);
 *   8. difference computed here (never sent by the client); a note is
 *      required when |difference| > the restaurant threshold (strictly
 *      greater — exactly at the threshold needs no note);
 *   9. canonical report + sha256, insert, AuditLog, activity event.
 *
 * Realtime goes out only after COMMIT (RestaurantActivityCreated is a
 * ShouldDispatchAfterCommit broadcast).
 */
class CloseRestaurantDayAction
{
    public function __construct(
        private readonly DayClosePeriodResolver $periodResolver,
        private readonly DayCloseReport $report,
        private readonly AuditLogger $auditLogger,
        private readonly RestaurantActivityRecorder $activityRecorder,
    ) {}

    /**
     * @param  array{idempotency_key: string, period_started_at: string, expected_cash_seen: string, opening_float?: ?string, counted_cash: string, cash_left_for_next_day?: ?string, cash_difference_note?: ?string, notes?: ?string}  $data
     * @return array{day_close: RestaurantDayClose, replayed: bool}
     */
    public function execute(Restaurant $restaurant, User $closedBy, array $data): array
    {
        $payloadHash = $this->payloadHash($data);

        try {
            return DB::transaction(fn () => $this->close($restaurant, $closedBy, $data, $payloadHash));
        } catch (UniqueConstraintViolationException $e) {
            // Final safety net — the exclusive lock already serializes
            // closes of one restaurant, so this is not the expected path.
            $existing = RestaurantDayClose::query()
                ->where('restaurant_id', $restaurant->id)
                ->where('idempotency_key', $data['idempotency_key'])
                ->first();

            if ($existing === null) {
                throw $e;
            }

            return $this->replayOrConflict($existing, $payloadHash);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{day_close: RestaurantDayClose, replayed: bool}
     */
    private function close(Restaurant $restaurant, User $closedBy, array $data, string $payloadHash): array
    {
        RestaurantOperationalLock::exclusive($restaurant->id);

        $existing = RestaurantDayClose::query()
            ->where('restaurant_id', $restaurant->id)
            ->where('idempotency_key', $data['idempotency_key'])
            ->first();

        if ($existing !== null) {
            return $this->replayOrConflict($existing, $payloadHash);
        }

        $endUtc = CarbonImmutable::now('UTC')->startOfSecond();
        $period = $this->periodResolver->resolve($restaurant, $endUtc);

        if (! CarbonImmutable::parse($data['period_started_at'])->equalTo($period->startUtc)) {
            throw DayCloseException::periodChanged(DayCloseFormat::instant($period->startUtc));
        }

        if ($period->businessDateAlreadyClosed) {
            throw DayCloseException::businessDateAlreadyClosed($period->businessDate);
        }

        if ($period->isEmpty()) {
            throw DayCloseException::emptyPeriod();
        }

        $live = $this->report->liveSections($restaurant, $period);

        if ($live['blockers'] !== []) {
            throw DayCloseException::blocked($live['blockers']);
        }

        $openingFloatCents = $this->openingFloatCents($restaurant, $period, $data['opening_float'] ?? null);
        $expectedCents = DayCloseReport::expectedCashCents($openingFloatCents, $live);

        if ($expectedCents < 0) {
            throw DayCloseException::expectedCashNegative(DayCloseFormat::money($expectedCents));
        }

        if ($expectedCents !== Money::decimalToCents($data['expected_cash_seen'])) {
            throw DayCloseException::cashExpectationChanged(DayCloseFormat::money($expectedCents));
        }

        $countedCents = Money::decimalToCents($data['counted_cash']);
        $differenceCents = $countedCents - $expectedCents;
        $thresholdCents = Money::decimalToCents((string) $restaurant->settings->cash_difference_note_threshold);
        $differenceNote = DayCloseFormat::plainText($data['cash_difference_note'] ?? null);

        if (abs($differenceCents) > $thresholdCents && $differenceNote === null) {
            throw ValidationException::withMessages([
                'cash_difference_note' => 'A note is required when the cash difference exceeds '.Money::centsToDecimal($thresholdCents).'.',
            ]);
        }

        $notes = DayCloseFormat::plainText($data['notes'] ?? null);
        $leftForNextDay = isset($data['cash_left_for_next_day']) ? Money::decimalToCents($data['cash_left_for_next_day']) : null;
        $hasIncidents = DayCloseReport::hasIncidents($live, $differenceCents, $notes);
        $closedAt = CarbonImmutable::now('UTC')->startOfSecond();
        $publicId = (string) Str::uuid();

        $cash = [
            'opening_float' => DayCloseFormat::money($openingFloatCents),
            'opening_float_source' => $this->report->openingFloat($restaurant, $period)['source'],
            'cash_received' => DayCloseFormat::money($live['financial_raw']['by_method_cents']['cash']),
            'cash_pay_ins' => DayCloseFormat::money($live['cash_movements_raw']['pay_ins_cents']),
            'cash_pay_outs' => DayCloseFormat::money($live['cash_movements_raw']['pay_outs_cents']),
            'expected_cash' => DayCloseFormat::money($expectedCents),
            'counted_cash' => DayCloseFormat::money($countedCents),
            'cash_difference' => DayCloseFormat::money($differenceCents),
            'cash_difference_note' => $differenceNote,
            'cash_difference_note_threshold' => DayCloseFormat::money($thresholdCents),
            'cash_left_for_next_day' => $leftForNextDay !== null ? DayCloseFormat::money($leftForNextDay) : null,
            'movements' => $live['cash_movements_raw']['movements'],
        ];

        $closing = [
            'public_id' => $publicId,
            'closed_by' => ['id' => $closedBy->id, 'name' => $closedBy->name],
            'closed_at' => DayCloseFormat::instant($closedAt),
            'notes' => $notes,
        ];

        $report = DayCloseReport::report($restaurant, $period, $live, $cash, $closing, $hasIncidents, $differenceCents);
        $financial = $live['financial_raw'];
        $operations = $live['operations'];
        $feedback = $live['feedback'];

        $dayClose = RestaurantDayClose::query()->create([
            'public_id' => $publicId,
            'organization_id' => $restaurant->organization_id,
            'restaurant_id' => $restaurant->id,
            'business_date' => $period->businessDate,
            'business_date_from' => $period->businessDateFrom,
            'period_started_at' => $period->startUtc,
            'period_ended_at' => $period->endUtc,
            'timezone' => $period->timezone,
            'currency' => $live['financial']['currency'],
            'closed_by_user_id' => $closedBy->id,
            'closed_by_name_snapshot' => $closedBy->name,
            'closed_at' => $closedAt,
            'total_received' => DayCloseFormat::money($financial['total_received_cents']),
            'cash_received' => DayCloseFormat::money($financial['by_method_cents']['cash']),
            'card_received' => DayCloseFormat::money($financial['by_method_cents']['card']),
            'other_received' => DayCloseFormat::money($financial['by_method_cents']['other']),
            'payments_count' => $financial['payments_count'],
            'sessions_with_payments' => $financial['sessions_with_payments'],
            'average_ticket' => DayCloseFormat::money($financial['average_ticket_cents']),
            'opening_float' => $cash['opening_float'],
            'cash_pay_ins' => $cash['cash_pay_ins'],
            'cash_pay_outs' => $cash['cash_pay_outs'],
            'expected_cash' => $cash['expected_cash'],
            'counted_cash' => $cash['counted_cash'],
            'cash_difference' => $cash['cash_difference'],
            'cash_left_for_next_day' => $cash['cash_left_for_next_day'],
            'cash_difference_note' => $differenceNote,
            'orders_registered' => $operations['orders']['registered'],
            'orders_valid' => $operations['orders']['valid'],
            'orders_served' => $operations['orders']['served'],
            'orders_rejected' => $operations['orders']['rejected'],
            'sessions_opened' => $operations['sessions']['opened'],
            'sessions_closed' => $operations['sessions']['closed'],
            'guests' => $operations['guests'],
            'feedback_count' => $feedback['count'],
            'feedback_avg_overall' => $feedback['avg_overall'],
            'critical_feedback_count' => $feedback['critical_count'],
            'low_dimension_feedback_count' => $feedback['low_dimension_count'],
            'delays_count' => $live['delays']['total_count'],
            'unavailable_products_count' => $live['product_availability']['count'],
            'notes' => $notes,
            'has_incidents' => $hasIncidents,
            'report' => $report,
            'report_schema_version' => DayCloseReport::REPORT_SCHEMA_VERSION,
            'report_sha256' => DayCloseFormat::hash($report),
            'idempotency_key' => $data['idempotency_key'],
            'payload_hash' => $payloadHash,
        ]);

        $this->auditLogger->log(
            organizationId: $restaurant->organization_id,
            restaurantId: $restaurant->id,
            actorType: AuditLog::ACTOR_USER,
            actor: $closedBy,
            event: AuditLog::EVENT_DAY_CLOSE_COMPLETED,
            resourceType: AuditLog::RESOURCE_DAY_CLOSE,
            resourceId: $dayClose->id,
            metadata: [
                'business_date' => $period->businessDate,
                'period_started_at' => DayCloseFormat::instant($period->startUtc),
                'period_ended_at' => DayCloseFormat::instant($period->endUtc),
                'total_received' => $dayClose->total_received,
                'expected_cash' => $dayClose->expected_cash,
                'counted_cash' => $dayClose->counted_cash,
                'cash_difference' => $dayClose->cash_difference,
                'has_incidents' => $hasIncidents,
                'report_sha256' => $dayClose->report_sha256,
            ],
        );

        $this->activityRecorder->record(
            restaurantId: $restaurant->id,
            type: RestaurantActivityType::DAY_CLOSE_COMPLETED,
            actor: ActivityActor::staff($closedBy),
            metadata: [
                'day_close_public_id' => $publicId,
                'business_date' => $period->businessDate,
                'total_received' => $dayClose->total_received,
                'cash_difference' => $dayClose->cash_difference,
                'has_incidents' => $hasIncidents,
            ],
            occurredAt: $closedAt,
        );

        return ['day_close' => $dayClose, 'replayed' => false];
    }

    /**
     * The opening float is never free input when it is known: inherited
     * from the previous close or the restaurant default it cannot be
     * changed at close time (any real drawer discrepancy must surface as
     * the cash difference). Only when the source is "required" must the
     * closer state it.
     */
    private function openingFloatCents(Restaurant $restaurant, DayClosePeriod $period, ?string $given): int
    {
        $float = $this->report->openingFloat($restaurant, $period);

        if ($float['cents'] === null) {
            if ($given === null) {
                throw ValidationException::withMessages(['opening_float' => 'The opening float is required: there is no previous close nor a restaurant default.']);
            }

            return Money::decimalToCents($given);
        }

        if ($given !== null && Money::decimalToCents($given) !== $float['cents']) {
            throw DayCloseException::openingFloatMismatch(DayCloseFormat::money($float['cents']));
        }

        return $float['cents'];
    }

    /**
     * @return array{day_close: RestaurantDayClose, replayed: bool}
     */
    private function replayOrConflict(RestaurantDayClose $existing, string $payloadHash): array
    {
        if (! hash_equals($existing->payload_hash, $payloadHash)) {
            throw DayCloseException::idempotencyKeyReused();
        }

        return ['day_close' => $existing, 'replayed' => true];
    }

    /**
     * Over the client's inputs only (never server-computed values), money
     * normalized to cents so "10.5" and "10.50" are the same request.
     *
     * @param  array<string, mixed>  $data
     */
    private function payloadHash(array $data): string
    {
        $money = fn (?string $value) => $value === null ? null : Money::decimalToCents($value);

        return hash('sha256', json_encode([
            'period_started_at' => CarbonImmutable::parse($data['period_started_at'])->utc()->getTimestamp(),
            'expected_cash_seen' => $money($data['expected_cash_seen']),
            'opening_float' => $money($data['opening_float'] ?? null),
            'counted_cash' => $money($data['counted_cash']),
            'cash_left_for_next_day' => $money($data['cash_left_for_next_day'] ?? null),
            'cash_difference_note' => DayCloseFormat::plainText($data['cash_difference_note'] ?? null),
            'notes' => DayCloseFormat::plainText($data['notes'] ?? null),
        ]));
    }
}
