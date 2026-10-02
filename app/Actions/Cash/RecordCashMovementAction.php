<?php

namespace App\Actions\Cash;

use App\Exceptions\DayClose\DayCloseException;
use App\Models\AuditLog;
use App\Models\Restaurant;
use App\Models\RestaurantCashMovement;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Money\Money;
use App\Support\Restaurants\RestaurantOperationalLock;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Records a cash drawer pay-in/pay-out (CARTA 9.1A). Append-only, under
 * the SHARED operational lock (it changes the Cierre Diario's expected
 * cash), idempotent by (restaurant_id, Idempotency-Key) with the same
 * replay/409 semantics as payments.
 *
 * A pay-out is not checked against the cash available at that moment: the
 * opening float may still be unknown (first close without a default), and
 * cash movements never block each other. The close itself refuses a
 * negative expected cash (EXPECTED_CASH_NEGATIVE) and the preview reports
 * it as a blocker, so the inconsistency can't be closed silently.
 */
class RecordCashMovementAction
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * @param  array{type: string, amount: string, reason: string, idempotency_key: string}  $data
     * @return array{movement: RestaurantCashMovement, replayed: bool}
     */
    public function execute(Restaurant $restaurant, User $actor, array $data): array
    {
        $payloadHash = hash('sha256', json_encode([
            'type' => $data['type'],
            'amount' => Money::decimalToCents($data['amount']),
            'reason' => trim($data['reason']),
        ]));

        try {
            return DB::transaction(function () use ($restaurant, $actor, $data, $payloadHash) {
                RestaurantOperationalLock::shared($restaurant->id);

                $existing = $this->findByKey($restaurant, $data['idempotency_key']);

                if ($existing !== null) {
                    return $this->replayOrConflict($existing, $payloadHash);
                }

                $movement = RestaurantCashMovement::query()->create([
                    'restaurant_id' => $restaurant->id,
                    'type' => $data['type'],
                    'amount' => Money::centsToDecimal(Money::decimalToCents($data['amount'])),
                    'reason' => trim($data['reason']),
                    'recorded_by_user_id' => $actor->id,
                    'recorded_by_name_snapshot' => $actor->name,
                    'recorded_at' => now(),
                    'idempotency_key' => $data['idempotency_key'],
                    'payload_hash' => $payloadHash,
                ]);

                $this->auditLogger->log(
                    organizationId: $restaurant->organization_id,
                    restaurantId: $restaurant->id,
                    actorType: AuditLog::ACTOR_USER,
                    actor: $actor,
                    event: AuditLog::EVENT_CASH_MOVEMENT_RECORDED,
                    resourceType: AuditLog::RESOURCE_CASH_MOVEMENT,
                    resourceId: $movement->id,
                    metadata: ['type' => $movement->type, 'amount' => $movement->amount, 'reason' => $movement->reason],
                );

                return ['movement' => $movement, 'replayed' => false];
            });
        } catch (UniqueConstraintViolationException $e) {
            // Lost a race against a concurrent request with the same key.
            $existing = $this->findByKey($restaurant, $data['idempotency_key']);

            if ($existing === null) {
                throw $e;
            }

            return $this->replayOrConflict($existing, $payloadHash);
        }
    }

    private function findByKey(Restaurant $restaurant, string $key): ?RestaurantCashMovement
    {
        return RestaurantCashMovement::query()
            ->where('restaurant_id', $restaurant->id)
            ->where('idempotency_key', $key)
            ->first();
    }

    /**
     * @return array{movement: RestaurantCashMovement, replayed: bool}
     */
    private function replayOrConflict(RestaurantCashMovement $existing, string $payloadHash): array
    {
        if (! hash_equals($existing->payload_hash, $payloadHash)) {
            throw DayCloseException::idempotencyKeyReused();
        }

        return ['movement' => $existing, 'replayed' => true];
    }
}
