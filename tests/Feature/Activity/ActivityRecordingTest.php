<?php

namespace Tests\Feature\Activity;

use App\Actions\Orders\ApproveOrderAction;
use App\Actions\Orders\RejectOrderAction;
use App\Actions\Tables\CloseTableAction;
use App\Exceptions\Billing\TableSessionNotPaidException;
use App\Models\Order;
use App\Models\RestaurantActivityEvent;
use App\Models\TableRequest;
use App\Support\Activity\ActivityActor;
use App\Support\Activity\RestaurantActivityRecorder;
use App\Support\Activity\RestaurantActivityType;
use App\Support\Billing\SessionBillCalculator;
use App\Support\Money\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Tests\Concerns\InteractsWithOrders;
use Tests\Concerns\InteractsWithPayments;
use Tests\Concerns\InteractsWithTableRequests;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * CARTA 6.1A — every connected domain transition writes exactly one
 * activity event, through the real Actions, with the right actor/table/
 * order snapshots; rolled-back and idempotently-replayed operations write
 * none. Metadata is compared with assertEquals: jsonb does not preserve
 * key order.
 */
class ActivityRecordingTest extends TestCase
{
    use InteractsWithOrders, InteractsWithPayments, InteractsWithTableRequests, InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
    }

    private function eventCount(string $type): int
    {
        return RestaurantActivityEvent::query()->where('type', $type)->count();
    }

    private function onlyEventOf(string $type): RestaurantActivityEvent
    {
        $this->assertSame(1, $this->eventCount($type), "Expected exactly one {$type} event.");

        return RestaurantActivityEvent::query()->where('type', $type)->firstOrFail();
    }

    public function test_opening_a_session_records_one_event_with_staff_and_table_snapshots(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $owner->update(['name' => 'Carlos García']);
        $table = $this->createTable($restaurant, 'Mesa 12');

        $session = $this->openSession($table, $owner, 4);

        $event = $this->onlyEventOf(RestaurantActivityType::TABLE_SESSION_OPENED);
        $this->assertSame($restaurant->id, $event->restaurant_id);
        $this->assertSame(RestaurantActivityType::CATEGORY_TABLES, $event->category);
        $this->assertSame(RestaurantActivityEvent::ACTOR_STAFF, $event->actor_type);
        $this->assertSame($owner->id, $event->actor_user_id);
        $this->assertSame('Carlos García', $event->actor_name_snapshot);
        $this->assertSame($table->id, $event->table_id);
        $this->assertSame('Mesa 12', $event->table_name_snapshot);
        $this->assertSame($session->id, $event->table_session_id);
        $this->assertSame(['guest_count' => 4], $event->metadata);
        $this->assertTrue($event->occurred_at->equalTo($session->opened_at));
    }

    public function test_customer_order_records_one_anonymous_event_with_order_snapshot_and_metadata(): void
    {
        [, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $table = $this->createTable($restaurant, 'Terraza 3');
        $session = $this->openSession($table, $owner);

        $order = $this->createCustomerOrder($table, [['restaurant_product_id' => $rp->id, 'quantity' => 3]], [
            'customer_name' => 'Ana',
            'note' => 'sin cebolla',
        ]);

        $event = $this->onlyEventOf(RestaurantActivityType::ORDER_CREATED);
        $this->assertSame(RestaurantActivityType::CATEGORY_ORDERS, $event->category);
        $this->assertSame(RestaurantActivityEvent::ACTOR_CUSTOMER, $event->actor_type);
        $this->assertNull($event->actor_user_id);
        $this->assertNull($event->actor_name_snapshot, 'A customer-provided name must never be stored as the actor.');
        $this->assertSame($order->id, $event->order_id);
        $this->assertSame("#{$order->id}", $event->order_reference);
        $this->assertSame('Terraza 3', $event->table_name_snapshot);
        $this->assertSame($session->id, $event->table_session_id);
        $this->assertEquals([
            'origin' => Order::ORIGIN_CUSTOMER_QR,
            'initial_status' => $order->status,
            'item_count' => 3,
            'total' => (string) $order->total,
        ], $event->metadata);

        $raw = json_encode($event->getAttributes());
        $this->assertStringNotContainsString('sin cebolla', $raw);
        $this->assertStringNotContainsString('Ana', $raw);
        $this->assertStringNotContainsString($table->public_token, $raw);
        $this->assertStringNotContainsString($session->feedback_token, $raw);
    }

    public function test_waiter_order_records_one_staff_event(): void
    {
        [, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);

        $this->createWaiterOrder($table, $owner, [['restaurant_product_id' => $rp->id, 'quantity' => 2]]);

        $event = $this->onlyEventOf(RestaurantActivityType::ORDER_CREATED);
        $this->assertSame(RestaurantActivityEvent::ACTOR_STAFF, $event->actor_type);
        $this->assertSame($owner->id, $event->actor_user_id);
        $this->assertSame(Order::ORIGIN_WAITER, $event->metadata['origin']);
        $this->assertSame(Order::STATUS_CONFIRMED, $event->metadata['initial_status']);
        $this->assertSame(2, $event->metadata['item_count']);
    }

    public function test_each_kitchen_lifecycle_step_records_exactly_one_event_stamped_by_its_actor(): void
    {
        [$organization, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W1', name: 'Lucas');
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);
        $order = $this->createWaiterOrder($table, $owner, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);

        $order = $this->advanceOrderTo($order, Order::STATUS_SERVED, $owner, servedBy: $waiter);

        foreach ([
            RestaurantActivityType::ORDER_ACCEPTED => 'accepted_at',
            RestaurantActivityType::ORDER_PREPARING => 'preparing_at',
            RestaurantActivityType::ORDER_READY => 'ready_at',
            RestaurantActivityType::ORDER_SERVED => 'served_at',
        ] as $type => $timestampColumn) {
            $event = $this->onlyEventOf($type);
            $this->assertSame($order->id, $event->order_id);
            $this->assertNull($event->metadata);
            $this->assertTrue($event->occurred_at->equalTo($order->{$timestampColumn}), "{$type} must carry the domain timestamp.");
        }

        $this->assertSame('Lucas', $this->onlyEventOf(RestaurantActivityType::ORDER_SERVED)->actor_name_snapshot);
        $this->assertSame($owner->id, $this->onlyEventOf(RestaurantActivityType::ORDER_READY)->actor_user_id);
    }

    public function test_approve_and_reject_are_distinct_known_causes(): void
    {
        [, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $this->requireOrderApproval($restaurant);
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);

        $approved = $this->createCustomerOrder($table, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);
        $rejected = $this->createCustomerOrder($table, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);
        app(ApproveOrderAction::class)->execute($approved, $owner);
        app(RejectOrderAction::class)->execute($rejected, $owner);

        $this->assertSame($approved->id, $this->onlyEventOf(RestaurantActivityType::ORDER_APPROVED)->order_id);
        $this->assertSame($rejected->id, $this->onlyEventOf(RestaurantActivityType::ORDER_REJECTED)->order_id);
        $this->assertSame(2, $this->eventCount(RestaurantActivityType::ORDER_CREATED));
    }

    public function test_call_waiter_lifecycle_records_one_event_per_step(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);

        $request = $this->createTableRequest($table, TableRequest::TYPE_CALL_WAITER, 'traiga agua');

        $created = $this->onlyEventOf(RestaurantActivityType::WAITER_REQUEST_CREATED);
        $this->assertSame(RestaurantActivityType::CATEGORY_SERVICE, $created->category);
        $this->assertSame(RestaurantActivityEvent::ACTOR_CUSTOMER, $created->actor_type);
        $this->assertSame($request->id, $created->table_request_id);
        $this->assertSame($session->id, $created->table_session_id);
        $this->assertStringNotContainsString('traiga agua', json_encode($created->getAttributes()));

        $this->advanceTableRequestTo($request, TableRequest::STATUS_COMPLETED, $owner);

        $this->assertSame($owner->id, $this->onlyEventOf(RestaurantActivityType::WAITER_REQUEST_ACKNOWLEDGED)->actor_user_id);
        $this->assertSame($request->id, $this->onlyEventOf(RestaurantActivityType::WAITER_REQUEST_COMPLETED)->table_request_id);
    }

    public function test_bill_request_records_one_billing_event(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);
        $this->createServedOrder($table, $owner);

        $request = $this->createTableRequest($table, TableRequest::TYPE_REQUEST_BILL);
        $this->advanceTableRequestTo($request, TableRequest::STATUS_ACKNOWLEDGED, $owner);

        $created = $this->onlyEventOf(RestaurantActivityType::BILL_REQUEST_CREATED);
        $this->assertSame(RestaurantActivityType::CATEGORY_BILLING, $created->category);
        $this->assertSame($request->id, $created->table_request_id);
        $this->assertSame(1, $this->eventCount(RestaurantActivityType::BILL_REQUEST_ACKNOWLEDGED));
        $this->assertSame(0, $this->eventCount(RestaurantActivityType::WAITER_REQUEST_CREATED));
    }

    public function test_payment_and_close_record_exactly_one_event_each(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant, 'Mesa 7');
        $session = $this->openSession($table, $owner);
        $this->createServedOrder($table, $owner);

        $payment = $this->recordPayment($session, $owner, '10.00', 'card')['payment'];

        $paid = $this->onlyEventOf(RestaurantActivityType::PAYMENT_RECORDED);
        $this->assertSame(RestaurantActivityType::CATEGORY_BILLING, $paid->category);
        $this->assertSame('Mesa 7', $paid->table_name_snapshot);
        $this->assertEquals(['payment_id' => $payment->id, 'amount' => '10.00', 'method' => 'card'], $paid->metadata);

        app(CloseTableAction::class)->execute($session->refresh(), $owner);

        $closed = $this->onlyEventOf(RestaurantActivityType::TABLE_SESSION_CLOSED);
        $this->assertSame(['total' => '10.00'], $closed->metadata);
        $this->assertSame($session->id, $closed->table_session_id);
    }

    public function test_table_request_cancellation_on_close_is_not_part_of_the_feed(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);
        $this->createServedOrder($table, $owner);
        $this->createTableRequest($table, TableRequest::TYPE_CALL_WAITER);
        $this->recordPayment($session, $owner, '10.00');

        $before = RestaurantActivityEvent::query()->count();
        app(CloseTableAction::class)->execute($session->refresh(), $owner);

        $this->assertSame($before + 1, RestaurantActivityEvent::query()->count());
    }

    public function test_idempotent_public_order_replay_records_no_second_event(): void
    {
        [, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);
        $items = [['restaurant_product_id' => $rp->id, 'quantity' => 1]];

        $first = $this->createCustomerOrder($table, $items, ['idempotency_key' => 'k-1']);
        $second = $this->createCustomerOrder($table, $items, ['idempotency_key' => 'k-1']);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, $this->eventCount(RestaurantActivityType::ORDER_CREATED));
    }

    public function test_idempotent_public_order_replay_over_http_records_no_second_event(): void
    {
        [, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);
        $payload = ['items' => [['restaurant_product_id' => $rp->id, 'quantity' => 1]]];

        $this->withHeader('Idempotency-Key', 'retry-1')->postJson("/api/v1/public/tables/{$table->public_token}/orders", $payload)->assertCreated();
        $this->withHeader('Idempotency-Key', 'retry-1')->postJson("/api/v1/public/tables/{$table->public_token}/orders", $payload)->assertOk();

        $this->assertSame(1, $this->eventCount(RestaurantActivityType::ORDER_CREATED));
    }

    public function test_idempotent_payment_replay_records_no_second_event(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);
        $this->createServedOrder($table, $owner);

        $first = $this->recordPayment($session, $owner, '4.00', extra: ['idempotency_key' => 'pay-1']);
        $replay = $this->recordPayment($session, $owner, '4.00', extra: ['idempotency_key' => 'pay-1']);

        $this->assertTrue($replay['replayed']);
        $this->assertSame($first['payment']->id, $replay['payment']->id);
        $this->assertSame(1, $this->eventCount(RestaurantActivityType::PAYMENT_RECORDED));
    }

    public function test_a_rolled_back_operation_leaves_no_activity_behind(): void
    {
        [, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);
        $before = RestaurantActivityEvent::query()->count();

        try {
            DB::transaction(function () use ($table, $owner, $rp) {
                $this->createWaiterOrder($table, $owner, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);

                throw new RuntimeException('forced rollback after the order was written');
            });
        } catch (RuntimeException) {
            // expected
        }

        $this->assertSame(0, Order::query()->count());
        $this->assertSame($before, RestaurantActivityEvent::query()->count());
    }

    public function test_a_failed_precondition_records_nothing(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);
        $this->createServedOrder($table, $owner);
        $before = RestaurantActivityEvent::query()->count();

        // Unpaid: CloseTableAction refuses before writing anything.
        try {
            app(CloseTableAction::class)->execute($session, $owner);
            $this->fail('Closing an unpaid session should have been refused.');
        } catch (TableSessionNotPaidException) {
            // expected
        }

        $this->assertSame($before, RestaurantActivityEvent::query()->count());
    }

    public function test_product_availability_changes_record_menu_events_and_no_ops_do_not(): void
    {
        [, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $rp->product->update(['internal_name' => 'Croquetas caseras']);

        $this->actingAs($owner, 'web')->patchJson("/api/v1/restaurant-products/{$rp->id}", ['available' => false])->assertOk();
        $this->actingAs($owner, 'web')->patchJson("/api/v1/restaurant-products/{$rp->id}", ['available' => false])->assertOk();
        $this->actingAs($owner, 'web')->patchJson("/api/v1/restaurant-products/{$rp->id}", ['price' => 12.5])->assertOk();
        $this->actingAs($owner, 'web')->patchJson("/api/v1/restaurant-products/{$rp->id}", ['available' => true])->assertOk();

        $unavailable = $this->onlyEventOf(RestaurantActivityType::PRODUCT_MARKED_UNAVAILABLE);
        $this->assertSame(RestaurantActivityType::CATEGORY_MENU, $unavailable->category);
        $this->assertEquals(['restaurant_product_id' => $rp->id, 'product_name' => 'Croquetas caseras'], $unavailable->metadata);
        $this->assertSame($owner->id, $unavailable->actor_user_id);
        $this->assertNull($unavailable->table_id);
        $this->assertSame(1, $this->eventCount(RestaurantActivityType::PRODUCT_MARKED_AVAILABLE));
    }

    public function test_snapshots_survive_later_renames(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $owner->update(['name' => 'Carlos García']);
        $table = $this->createTable($restaurant, 'Mesa 12');
        $this->openSession($table, $owner);

        $owner->update(['name' => 'Carlos G. Pérez']);
        $table->update(['name' => 'Mesa 12B']);

        $event = $this->onlyEventOf(RestaurantActivityType::TABLE_SESSION_OPENED);
        $this->assertSame('Carlos García', $event->actor_name_snapshot);
        $this->assertSame('Mesa 12', $event->table_name_snapshot);
    }

    public function test_recorder_rejects_unknown_types_off_contract_metadata_and_foreign_context(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        [, , $otherRestaurant] = $this->createTenant();
        $foreignTable = $this->createTable($otherRestaurant);
        $recorder = app(RestaurantActivityRecorder::class);

        foreach ([
            fn () => $recorder->record($restaurant->id, 'order.teleported', ActivityActor::staff($owner)),
            fn () => $recorder->record($restaurant->id, RestaurantActivityType::TABLE_SESSION_OPENED, ActivityActor::staff($owner), metadata: ['guest_count' => 2, 'ip' => '1.2.3.4']),
            fn () => $recorder->record($restaurant->id, RestaurantActivityType::TABLE_SESSION_OPENED, ActivityActor::staff($owner), metadata: []),
            fn () => $recorder->record($restaurant->id, RestaurantActivityType::ORDER_READY, ActivityActor::staff($owner), table: $foreignTable),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('The recorder accepted an invalid activity.');
            } catch (InvalidArgumentException) {
                // expected
            }
        }

        $this->assertSame(0, RestaurantActivityEvent::query()->count());
    }

    public function test_every_type_has_a_category_and_every_category_is_used(): void
    {
        foreach (RestaurantActivityType::all() as $type) {
            $this->assertContains(RestaurantActivityType::categoryOf($type), RestaurantActivityType::CATEGORIES);
        }

        $this->assertEqualsCanonicalizing(
            RestaurantActivityType::CATEGORIES,
            array_values(array_unique(RestaurantActivityType::CATEGORY_BY_TYPE)),
        );
    }

    public function test_full_service_produces_one_event_per_step_in_order(): void
    {
        [, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);
        $order = $this->createWaiterOrder($table, $owner, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);
        $this->advanceOrderTo($order, Order::STATUS_SERVED, $owner);
        $this->createTableRequest($table, TableRequest::TYPE_CALL_WAITER);
        $this->createTableRequest($table, TableRequest::TYPE_REQUEST_BILL);
        $balance = SessionBillCalculator::summarize($session->refresh())['balanceCents'];
        $this->recordPayment($session, $owner, Money::centsToDecimal($balance));
        app(CloseTableAction::class)->execute($session->refresh(), $owner);

        $this->assertSame([
            RestaurantActivityType::TABLE_SESSION_OPENED,
            RestaurantActivityType::ORDER_CREATED,
            RestaurantActivityType::ORDER_ACCEPTED,
            RestaurantActivityType::ORDER_PREPARING,
            RestaurantActivityType::ORDER_READY,
            RestaurantActivityType::ORDER_SERVED,
            RestaurantActivityType::WAITER_REQUEST_CREATED,
            RestaurantActivityType::BILL_REQUEST_CREATED,
            RestaurantActivityType::PAYMENT_RECORDED,
            RestaurantActivityType::TABLE_SESSION_CLOSED,
        ], RestaurantActivityEvent::query()->orderBy('id')->pluck('type')->all());
    }
}
