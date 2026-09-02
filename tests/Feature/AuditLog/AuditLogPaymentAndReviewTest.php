<?php

namespace Tests\Feature\AuditLog;

use App\Models\AuditLog;
use App\Models\Order;
use App\Models\PaymentRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithOrders;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class AuditLogPaymentAndReviewTest extends TestCase
{
    use InteractsWithOrders, InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    public function test_payment_record_creation_records_audit_event_without_reference(): void
    {
        [$organization, $owner, $restaurant, $restaurantProduct] = $this->createTenantWithRestaurantProduct();
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);
        $order = $this->createWaiterOrder($table, $owner, [
            ['restaurant_product_id' => $restaurantProduct->id, 'quantity' => 1],
        ]);
        $order = $this->advanceOrderTo($order, Order::STATUS_SERVED, $owner);

        $this->actingAs($owner, 'web')
            ->postJson("/api/v1/table-sessions/{$session->id}/payments", [
                'method' => PaymentRecord::METHOD_CARD,
                'amount' => $order->total,
                'reference' => 'POS-SECRET-REF',
            ])
            ->assertCreated();

        $log = AuditLog::query()->where('event', AuditLog::EVENT_PAYMENT_RECORD_CREATED)->first();
        $this->assertNotNull($log);
        $this->assertSame(AuditLog::ACTOR_USER, $log->actor_type);
        $this->assertSame($owner->id, $log->actor_user_id);
        $this->assertSame($restaurant->id, $log->restaurant_id);
        $this->assertEqualsCanonicalizing(['table_session_id', 'method', 'amount', 'currency'], array_keys($log->metadata));
        $this->assertStringNotContainsString('POS-SECRET-REF', json_encode($log->metadata));
    }

    public function test_payment_idempotency_replay_does_not_duplicate_audit(): void
    {
        [$organization, $owner, $restaurant, $restaurantProduct] = $this->createTenantWithRestaurantProduct();
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);
        $order = $this->createWaiterOrder($table, $owner, [
            ['restaurant_product_id' => $restaurantProduct->id, 'quantity' => 1],
        ]);
        $order = $this->advanceOrderTo($order, Order::STATUS_SERVED, $owner);

        $payload = ['method' => PaymentRecord::METHOD_CASH, 'amount' => $order->total];

        $this->actingAs($owner, 'web')
            ->withHeader('Idempotency-Key', 'pay-1')
            ->postJson("/api/v1/table-sessions/{$session->id}/payments", $payload)
            ->assertCreated();
        $this->actingAs($owner, 'web')
            ->withHeader('Idempotency-Key', 'pay-1')
            ->postJson("/api/v1/table-sessions/{$session->id}/payments", $payload)
            ->assertOk();

        $this->assertSame(1, AuditLog::query()->where('event', AuditLog::EVENT_PAYMENT_RECORD_CREATED)->count());
    }

    public function test_staff_review_creation_records_rating_but_not_comment(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');

        $this->actingAs($owner, 'web')
            ->postJson("/api/v1/staff/{$waiter->id}/reviews", [
                'rating' => 4,
                'comment' => 'Very secret comment',
            ])
            ->assertCreated();

        $log = AuditLog::query()->where('event', AuditLog::EVENT_STAFF_REVIEW_CREATED)->first();
        $this->assertNotNull($log);
        $this->assertSame(AuditLog::ACTOR_USER, $log->actor_type);
        $this->assertSame($owner->id, $log->actor_user_id);
        $this->assertEquals(['staff_user_id' => $waiter->id, 'rating' => 4], $log->metadata);
        $this->assertStringNotContainsString('Very secret comment', json_encode($log->metadata));
    }

    public function test_self_review_rejection_records_no_audit_event(): void
    {
        [$organization, , $restaurant] = $this->createTenant();
        $manager = $this->createStaff($organization, $restaurant, 'manager', 'M-1');

        $this->actingAs($manager, 'web')
            ->postJson("/api/v1/staff/{$manager->id}/reviews", ['rating' => 5])
            ->assertStatus(422);

        $this->assertSame(0, AuditLog::query()->where('event', AuditLog::EVENT_STAFF_REVIEW_CREATED)->count());
    }
}
