<?php

namespace App\Actions\DailyOperation;

use App\Events\Realtime\DailyOperationChanged;
use App\Exceptions\DailyOperation\DailyOperationException;
use App\Models\AuditLog;
use App\Models\Restaurant;
use App\Models\RestaurantDailyOperation;
use App\Models\RestaurantDayClose;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\DailyOperation\DailyOperationState;
use App\Support\DayClose\DayClosePeriodResolver;
use App\Support\DayClose\DayCloseReport;
use App\Support\Money\Money;
use App\Support\Restaurants\RestaurantOperationalLock;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Opens the restaurant's operational day — ONE transaction:
 *
 *   1. EXCLUSIVE RestaurantOperationalLock (same lock as the Cierre
 *      Diario): two openers, or an opener and a closer, are serialized;
 *   2. idempotent replay (same Idempotency-Key + same payload => the same
 *      operation, 200; different payload => 409 IDEMPOTENCY_KEY_REUSED);
 *   3. an already open day => 409 DAILY_OPERATION_ALREADY_OPEN with its
 *      non-financial state (a second person/tab never creates another one
 *      nor a second opening float);
 *   4. business date = DayClosePeriodResolver::businessDateOf(now) — the
 *      restaurant timezone and business_day_cutoff_time, the same rule the
 *      close uses; a date already closed (restaurant_day_closes has one
 *      close per date) => 409 BUSINESS_DATE_ALREADY_CLOSED;
 *   5. the declared opening float is stored with what the existing
 *      precedence suggested (previous close / restaurant default) for audit.
 *
 * Opening never touches product availability (the last state is kept) and
 * never opens tables. Realtime only after COMMIT.
 */
class OpenDailyOperationAction
{
    public function __construct(
        private readonly DayClosePeriodResolver $periodResolver,
        private readonly DayCloseReport $report,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * @param  array{idempotency_key: string, opening_float: string}  $data
     * @return array{daily_operation: RestaurantDailyOperation, replayed: bool}
     */
    public function execute(Restaurant $restaurant, User $openedBy, array $data): array
    {
        $payloadHash = hash('sha256', json_encode(['opening_float' => Money::decimalToCents($data['opening_float'])]));

        try {
            return DB::transaction(fn () => $this->open($restaurant, $openedBy, $data, $payloadHash));
        } catch (UniqueConstraintViolationException $e) {
            // Safety net only: the exclusive lock already serializes openers.
            $existing = $this->byKey($restaurant, $data['idempotency_key']);
            if ($existing !== null) {
                return $this->replayOrConflict($existing, $payloadHash);
            }
            $open = $restaurant->openDailyOperation()->first();
            if ($open !== null) {
                throw DailyOperationException::alreadyOpen(DailyOperationState::publicState($open));
            }

            throw $e;
        }
    }

    /**
     * @param  array{idempotency_key: string, opening_float: string}  $data
     * @return array{daily_operation: RestaurantDailyOperation, replayed: bool}
     */
    private function open(Restaurant $restaurant, User $openedBy, array $data, string $payloadHash): array
    {
        RestaurantOperationalLock::exclusive($restaurant->id);

        $existing = $this->byKey($restaurant, $data['idempotency_key']);
        if ($existing !== null) {
            return $this->replayOrConflict($existing, $payloadHash);
        }

        $open = $restaurant->openDailyOperation()->first();
        if ($open !== null) {
            throw DailyOperationException::alreadyOpen(DailyOperationState::publicState($open));
        }

        $settings = $restaurant->settings;
        $now = CarbonImmutable::now('UTC')->startOfSecond();
        $businessDate = DayClosePeriodResolver::businessDateOf($now, $settings->timezone, $settings->business_day_cutoff_time);

        $lastClosedDate = RestaurantDayClose::query()->where('restaurant_id', $restaurant->id)->max('business_date');
        $alreadyUsed = RestaurantDailyOperation::query()->where('restaurant_id', $restaurant->id)
            ->whereDate('business_date', $businessDate)->exists();
        if ($alreadyUsed || ($lastClosedDate !== null && $businessDate <= substr((string) $lastClosedDate, 0, 10))) {
            throw DailyOperationException::businessDateAlreadyClosed($businessDate);
        }

        $suggested = $this->report->suggestedOpeningFloat($restaurant, $this->periodResolver->resolve($restaurant, $now));
        $openingFloat = Money::centsToDecimal(Money::decimalToCents($data['opening_float']));

        $operation = RestaurantDailyOperation::query()->create([
            'public_id' => (string) Str::uuid(),
            'organization_id' => $restaurant->organization_id,
            'restaurant_id' => $restaurant->id,
            'business_date' => $businessDate,
            'timezone' => $settings->timezone,
            'currency' => $settings->currency,
            'status' => RestaurantDailyOperation::STATUS_OPEN,
            'opened_by_user_id' => $openedBy->id,
            'opened_by_name_snapshot' => $openedBy->name,
            'opened_at' => $now,
            'opening_float' => $openingFloat,
            'suggested_opening_float' => $suggested['cents'] !== null ? Money::centsToDecimal($suggested['cents']) : null,
            'suggested_opening_float_source' => $suggested['source'],
            'idempotency_key' => $data['idempotency_key'],
            'payload_hash' => $payloadHash,
        ]);

        $this->auditLogger->log(
            organizationId: $restaurant->organization_id,
            restaurantId: $restaurant->id,
            actorType: AuditLog::ACTOR_USER,
            actor: $openedBy,
            event: AuditLog::EVENT_DAILY_OPERATION_OPENED,
            resourceType: AuditLog::RESOURCE_DAILY_OPERATION,
            resourceId: $operation->id,
            metadata: [
                'business_date' => $businessDate,
                'opening_float' => $openingFloat,
                'suggested_opening_float' => $operation->suggested_opening_float,
                'suggested_opening_float_source' => $suggested['source'],
            ],
        );

        DailyOperationChanged::dispatch($restaurant->id, $operation->public_id, RestaurantDailyOperation::STATUS_OPEN, $businessDate);

        return ['daily_operation' => $operation, 'replayed' => false];
    }

    private function byKey(Restaurant $restaurant, string $key): ?RestaurantDailyOperation
    {
        return RestaurantDailyOperation::query()->where('restaurant_id', $restaurant->id)->where('idempotency_key', $key)->first();
    }

    /**
     * @return array{daily_operation: RestaurantDailyOperation, replayed: bool}
     */
    private function replayOrConflict(RestaurantDailyOperation $existing, string $payloadHash): array
    {
        if (! hash_equals($existing->payload_hash, $payloadHash)) {
            throw DailyOperationException::idempotencyKeyReused();
        }

        return ['daily_operation' => $existing, 'replayed' => true];
    }
}
