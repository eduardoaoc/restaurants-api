<?php

namespace Tests\Feature\Realtime;

use App\Events\Realtime\OrderCreated;
use App\Events\Realtime\PaymentRecorded;
use App\Events\Realtime\TableSessionOpened;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Bloco 7 — the shared envelope contract every RealtimeEvent carries
 * (event_id, schema_version, occurred_at, restaurant_id), the private
 * per-restaurant channel, and that no event payload ever exceeds its own
 * small, explicit field set (item 47/48/49: never raw Model
 * serialization).
 */
class EventPayloadContractTest extends TestCase
{
    public function test_broadcasts_on_the_private_restaurant_channel(): void
    {
        $event = new TableSessionOpened(7, 1, 2, 4, Carbon::now());

        $channels = $event->broadcastOn();

        $this->assertCount(1, $channels);
        $this->assertInstanceOf(PrivateChannel::class, $channels[0]);
        $this->assertSame('private-restaurant.7', $channels[0]->name);
    }

    public function test_envelope_carries_event_id_schema_version_occurred_at_and_restaurant_id(): void
    {
        $event = new TableSessionOpened(7, 1, 2, 4, Carbon::now());

        $payload = $event->broadcastWith();

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            $payload['event_id'],
        );
        $this->assertSame(1, $payload['schema_version']);
        $this->assertSame(7, $payload['restaurant_id']);
        // ISO 8601, not a localized/formatted timestamp (item 17).
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $payload['occurred_at']);
    }

    public function test_two_instances_of_the_same_event_get_different_event_ids(): void
    {
        $first = new TableSessionOpened(7, 1, 2, 4, Carbon::now());
        $second = new TableSessionOpened(7, 1, 2, 4, Carbon::now());

        $this->assertNotSame($first->broadcastWith()['event_id'], $second->broadcastWith()['event_id']);
    }

    public function test_order_created_payload_has_exactly_the_documented_fields_no_more(): void
    {
        $event = new OrderCreated(7, 1, 2, 3, 'waiter', 'confirmed', Carbon::now());

        $payload = $event->broadcastWith();

        $this->assertSame([
            'event_id', 'schema_version', 'occurred_at', 'restaurant_id',
            'table_id', 'table_session_id', 'order_id', 'origin', 'status', 'created_at',
        ], array_keys($payload));
    }

    public function test_payment_recorded_never_carries_gateway_or_card_fields(): void
    {
        $event = new PaymentRecorded(7, 2, 1, 9, '25.00', 'cash', Carbon::now());

        $payload = $event->broadcastWith();

        foreach (['card_number', 'token', 'gateway_token', 'secret', 'password'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $payload);
        }

        $this->assertSame([
            'event_id', 'schema_version', 'occurred_at', 'restaurant_id',
            'table_session_id', 'table_id', 'payment_id', 'amount', 'payment_method', 'recorded_at',
        ], array_keys($payload));
    }

    public function test_broadcast_as_names_are_stable_wire_names_not_php_class_names(): void
    {
        $event = new TableSessionOpened(7, 1, 2, 4, Carbon::now());

        $this->assertSame('table.session.opened', $event->broadcastAs());
        $this->assertStringNotContainsString('\\', $event->broadcastAs());
    }
}
