<?php

namespace Tests\Feature\AuditLog;

use App\Models\AuditLog;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithOrders;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class AuditLogOrderTest extends TestCase
{
    use InteractsWithOrders, InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    public function test_public_order_creation_records_public_actor(): void
    {
        [$organization, $owner, $restaurant, $restaurantProduct] = $this->createTenantWithRestaurantProduct();
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);

        $this->createCustomerOrder($table, [
            ['restaurant_product_id' => $restaurantProduct->id, 'quantity' => 1],
        ]);

        $log = AuditLog::query()->where('event', AuditLog::EVENT_ORDER_CREATED)->first();
        $this->assertNotNull($log);
        $this->assertSame(AuditLog::ACTOR_PUBLIC, $log->actor_type);
        $this->assertNull($log->actor_user_id);
        $this->assertSame($organization->id, $log->organization_id);
        $this->assertSame($restaurant->id, $log->restaurant_id);
        $this->assertEquals(['origin' => Order::ORIGIN_CUSTOMER_QR, 'initial_status' => Order::STATUS_CONFIRMED], $log->metadata);
    }

    public function test_staff_order_creation_records_user_actor(): void
    {
        [$organization, $owner, $restaurant, $restaurantProduct] = $this->createTenantWithRestaurantProduct();
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');
        $table = $this->createTable($restaurant);
        $this->openSession($table, $waiter);

        $this->createWaiterOrder($table, $waiter, [
            ['restaurant_product_id' => $restaurantProduct->id, 'quantity' => 1],
        ]);

        $log = AuditLog::query()->where('event', AuditLog::EVENT_ORDER_CREATED)->first();
        $this->assertNotNull($log);
        $this->assertSame(AuditLog::ACTOR_USER, $log->actor_type);
        $this->assertSame($waiter->id, $log->actor_user_id);
        $this->assertEquals(['origin' => Order::ORIGIN_WAITER, 'initial_status' => Order::STATUS_CONFIRMED], $log->metadata);
    }

    public function test_public_order_idempotency_replay_does_not_duplicate_audit(): void
    {
        [$organization, $owner, $restaurant, $restaurantProduct] = $this->createTenantWithRestaurantProduct();
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);

        $items = [['restaurant_product_id' => $restaurantProduct->id, 'quantity' => 1]];

        $this->createCustomerOrder($table, $items, ['idempotency_key' => 'idem-1']);
        $this->createCustomerOrder($table, $items, ['idempotency_key' => 'idem-1']);

        $this->assertSame(1, AuditLog::query()->where('event', AuditLog::EVENT_ORDER_CREATED)->count());
    }

    public function test_order_approval_and_rejection_record_one_audit_event_each(): void
    {
        [$organization, $owner, $restaurant, $restaurantProduct] = $this->createTenantWithRestaurantProduct();
        $this->requireOrderApproval($restaurant);
        $table1 = $this->createTable($restaurant);
        $table2 = $this->createTable($restaurant);
        $this->openSession($table1, $owner);
        $this->openSession($table2, $owner);

        $approved = $this->createCustomerOrder($table1, [
            ['restaurant_product_id' => $restaurantProduct->id, 'quantity' => 1],
        ]);
        $rejected = $this->createCustomerOrder($table2, [
            ['restaurant_product_id' => $restaurantProduct->id, 'quantity' => 1],
        ]);

        $this->actingAs($owner, 'web')->postJson("/api/v1/orders/{$approved->id}/approve")->assertOk();
        $this->actingAs($owner, 'web')->postJson("/api/v1/orders/{$rejected->id}/reject")->assertOk();

        $this->assertSame(1, AuditLog::query()->where('event', AuditLog::EVENT_ORDER_APPROVED)->count());
        $this->assertSame(1, AuditLog::query()->where('event', AuditLog::EVENT_ORDER_REJECTED)->count());

        $approvedLog = AuditLog::query()->where('event', AuditLog::EVENT_ORDER_APPROVED)->first();
        $this->assertEquals(['previous_status' => Order::STATUS_WAITING_APPROVAL, 'new_status' => Order::STATUS_CONFIRMED], $approvedLog->metadata);
    }

    public function test_kitchen_lifecycle_transitions_record_correct_events(): void
    {
        [$organization, $owner, $restaurant, $restaurantProduct] = $this->createTenantWithRestaurantProduct();
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');
        $table = $this->createTable($restaurant);
        $this->openSession($table, $waiter);

        $order = $this->createWaiterOrder($table, $waiter, [
            ['restaurant_product_id' => $restaurantProduct->id, 'quantity' => 1],
        ]);

        $this->actingAs($owner, 'web')->postJson("/api/v1/orders/{$order->id}/accept")->assertOk();
        $this->actingAs($owner, 'web')->postJson("/api/v1/orders/{$order->id}/preparing")->assertOk();
        $this->actingAs($owner, 'web')->postJson("/api/v1/orders/{$order->id}/ready")->assertOk();
        $this->actingAs($owner, 'web')->postJson("/api/v1/orders/{$order->id}/served")->assertOk();

        foreach ([AuditLog::EVENT_ORDER_ACCEPTED, AuditLog::EVENT_ORDER_PREPARING, AuditLog::EVENT_ORDER_READY, AuditLog::EVENT_ORDER_SERVED] as $event) {
            $this->assertSame(1, AuditLog::query()->where('event', $event)->where('resource_id', $order->id)->count(), "expected exactly one {$event}");
        }

        $servedLog = AuditLog::query()->where('event', AuditLog::EVENT_ORDER_SERVED)->first();
        $this->assertEquals(['previous_status' => Order::STATUS_READY, 'new_status' => Order::STATUS_SERVED], $servedLog->metadata);
    }

    public function test_invalid_transition_records_no_audit_event(): void
    {
        [$organization, $owner, $restaurant, $restaurantProduct] = $this->createTenantWithRestaurantProduct();
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');
        $table = $this->createTable($restaurant);
        $this->openSession($table, $waiter);

        $order = $this->createWaiterOrder($table, $waiter, [
            ['restaurant_product_id' => $restaurantProduct->id, 'quantity' => 1],
        ]);

        // Order is confirmed, not accepted yet — "ready" is an invalid jump.
        $this->actingAs($owner, 'web')->postJson("/api/v1/orders/{$order->id}/ready")->assertStatus(409);

        $this->assertSame(0, AuditLog::query()->where('event', AuditLog::EVENT_ORDER_READY)->count());
    }
}
