<?php

namespace App\Support\Activity;

use App\Events\Realtime\RestaurantActivityCreated;
use App\Models\Order;
use App\Models\RestaurantActivityEvent;
use App\Models\Table;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * Single entry point for writing the operational activity feed (CARTA
 * 6.1A). No Action or Controller creates a RestaurantActivityEvent any
 * other way.
 *
 * Transaction strategy: called from inside the SAME DB::transaction() as
 * the domain mutation it describes, right next to the existing
 * AuditLogger call — the same pattern the whole codebase already uses. A
 * rolled-back mutation therefore rolls its activity row back with it, and
 * the broadcast (RestaurantActivityCreated, ShouldDispatchAfterCommit) is
 * only queued once that transaction commits.
 *
 * Idempotency: recording is tied to the code path that actually performs
 * the mutation, never to the request. An idempotent replay (public order,
 * payment) returns before reaching that code path, and a lost unique-key
 * race rolls the whole transaction back — so one real operation is always
 * exactly one activity event, with no dedup heuristic.
 *
 * Tenant integrity: a table/order passed in must belong to $restaurantId;
 * a mismatch is a programming error and throws before anything is
 * written. Metadata keys are checked against the per-type contract
 * (RestaurantActivityType::METADATA_KEYS), so the jsonb column never
 * turns into an untyped dump — and nothing like IPs, tokens, contact
 * details or customer notes can slip in unnoticed.
 */
class RestaurantActivityRecorder
{
    /**
     * @param  array<string, scalar|null>  $metadata
     */
    public function record(
        int $restaurantId,
        string $type,
        ActivityActor $actor,
        ?Table $table = null,
        ?int $tableSessionId = null,
        ?Order $order = null,
        ?int $tableRequestId = null,
        array $metadata = [],
        ?CarbonInterface $occurredAt = null,
    ): RestaurantActivityEvent {
        $category = RestaurantActivityType::categoryOf($type);

        if ($category === null) {
            throw new InvalidArgumentException("Unknown activity type '{$type}'.");
        }

        $expectedKeys = RestaurantActivityType::metadataKeysOf($type);
        $givenKeys = array_keys($metadata);
        sort($expectedKeys);
        sort($givenKeys);

        if ($expectedKeys !== $givenKeys) {
            throw new InvalidArgumentException("Metadata for '{$type}' must be exactly [".implode(', ', $expectedKeys).'].');
        }

        foreach ([$table, $order] as $model) {
            if ($model !== null && $model->restaurant_id !== $restaurantId) {
                throw new InvalidArgumentException('Activity context belongs to a different restaurant.');
            }
        }

        $event = RestaurantActivityEvent::query()->create([
            'restaurant_id' => $restaurantId,
            'type' => $type,
            'category' => $category,
            'occurred_at' => $occurredAt ?? now(),
            'actor_type' => $actor->type,
            'actor_user_id' => $actor->userId,
            'actor_name_snapshot' => $actor->name,
            'table_id' => $table?->id,
            'table_name_snapshot' => $table?->name,
            'table_session_id' => $tableSessionId,
            'order_id' => $order?->id,
            'order_reference' => $order ? self::orderReference($order) : null,
            'table_request_id' => $tableRequestId,
            'metadata' => $metadata === [] ? null : $metadata,
            'created_at' => now(),
        ]);

        RestaurantActivityCreated::dispatch($event);

        return $event;
    }

    /**
     * Same human reference OrderResource exposes as `order_number`.
     */
    private static function orderReference(Order $order): string
    {
        return sprintf('#%d', $order->id);
    }
}
