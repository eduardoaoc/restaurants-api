<?php

namespace App\Events\Realtime;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Shared envelope for every operational realtime event (Bloco 7).
 *
 * REST remains the canonical source of state (GET /operations/live is the
 * full snapshot); these events are best-effort, small, incremental
 * notifications on top of it — never a second way to mutate the domain,
 * never a full snapshot re-broadcast (see docs/realtime.md).
 *
 * `ShouldBroadcast` (not `ShouldBroadcastNow`) makes every broadcast go
 * through the queue: if Reverb is unreachable, only the queued
 * `BroadcastEvent` job fails/retries — the HTTP request that triggered the
 * domain mutation has already returned successfully by then. Combined
 * with `ShouldDispatchAfterCommit`, Laravel defers even running this
 * event's listeners (and therefore queuing that job at all) until the
 * enclosing DB transaction actually commits — a rolled-back mutation
 * broadcasts nothing (see AfterCommitBroadcastTest).
 *
 * Every concrete event fixes its own `restaurant_id` and a small,
 * explicit payload() — never raw Eloquent Model serialization (see
 * `broadcastWith()` below), so the wire contract can never accidentally
 * grow a sensitive or unstable field.
 */
abstract class RealtimeEvent implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable;

    public const SCHEMA_VERSION = 1;

    public readonly string $eventId;

    public readonly string $occurredAt;

    public function __construct(public readonly int $restaurantId)
    {
        $this->eventId = (string) Str::uuid();
        $this->occurredAt = Carbon::now()->toISOString();
    }

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("restaurant.{$this->restaurantId}")];
    }

    /**
     * The stable wire name a frontend can rely on — never the PHP FQCN.
     */
    abstract public function broadcastAs(): string;

    /**
     * @return array<string, mixed>
     */
    abstract protected function payload(): array;

    /**
     * @return array<string, mixed>
     */
    final public function broadcastWith(): array
    {
        return array_merge([
            'event_id' => $this->eventId,
            'schema_version' => self::SCHEMA_VERSION,
            'occurred_at' => $this->occurredAt,
            'restaurant_id' => $this->restaurantId,
        ], $this->payload());
    }
}
