<?php

namespace Tests\Feature\AuditLog;

use App\Models\AuditLog;
use App\Models\TableRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithOrders;
use Tests\Concerns\InteractsWithPayments;
use Tests\Concerns\InteractsWithTableRequests;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class AuditLogTableSessionAndRequestTest extends TestCase
{
    use InteractsWithOrders, InteractsWithPayments, InteractsWithTableRequests, InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    public function test_table_session_open_records_audit_event(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);

        $this->actingAs($owner, 'web')
            ->postJson("/api/v1/tables/{$table->id}/open", ['guest_count' => 2])
            ->assertCreated();

        $log = AuditLog::query()->where('event', AuditLog::EVENT_TABLE_SESSION_OPENED)->first();
        $this->assertNotNull($log);
        $this->assertSame($restaurant->id, $log->restaurant_id);
        $this->assertSame(AuditLog::ACTOR_USER, $log->actor_type);
        $this->assertSame($owner->id, $log->actor_user_id);
        $this->assertEquals(['table_id' => $table->id, 'status' => 'occupied'], $log->metadata);
    }

    public function test_close_with_open_requests_records_closed_and_cancelled_events(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);

        $requestA = $this->createTableRequest($table, TableRequest::TYPE_CALL_WAITER);
        $requestB = $this->createTableRequest($table, TableRequest::TYPE_REQUEST_BILL);

        $this->closeSessionWithFullPayment($session, $owner);

        $this->assertSame(1, AuditLog::query()->where('event', AuditLog::EVENT_TABLE_SESSION_CLOSED)->count());
        $this->assertSame(2, AuditLog::query()->where('event', AuditLog::EVENT_TABLE_REQUEST_CANCELLED)->count());

        $closedLog = AuditLog::query()->where('event', AuditLog::EVENT_TABLE_SESSION_CLOSED)->first();
        $this->assertSame($session->id, $closedLog->resource_id);
        $this->assertArrayHasKey('payment_status', $closedLog->metadata);
        $this->assertArrayHasKey('orders_total', $closedLog->metadata);
        $this->assertArrayHasKey('paid_total', $closedLog->metadata);

        foreach ([$requestA->id, $requestB->id] as $requestId) {
            $log = AuditLog::query()
                ->where('event', AuditLog::EVENT_TABLE_REQUEST_CANCELLED)
                ->where('resource_id', $requestId)
                ->first();
            $this->assertNotNull($log);
            $this->assertSame('table_session_closed', $log->metadata['reason']);
            $this->assertSame(TableRequest::STATUS_CANCELLED, $log->metadata['new_status']);
        }
    }

    public function test_public_table_request_creation_records_public_actor(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);

        $this->createTableRequest($table, TableRequest::TYPE_CALL_WAITER);

        $log = AuditLog::query()->where('event', AuditLog::EVENT_TABLE_REQUEST_CREATED)->first();
        $this->assertNotNull($log);
        $this->assertSame(AuditLog::ACTOR_PUBLIC, $log->actor_type);
        $this->assertNull($log->actor_user_id);
        $this->assertEquals([
            'type' => TableRequest::TYPE_CALL_WAITER,
            'status' => TableRequest::STATUS_PENDING,
            'table_session_id' => $table->activeSession->id,
        ], $log->metadata);
    }

    public function test_duplicate_public_table_request_records_no_extra_audit(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);

        $this->createTableRequest($table, TableRequest::TYPE_CALL_WAITER);

        $this->postJson("/api/v1/public/tables/{$table->public_token}/requests/call-waiter")
            ->assertStatus(409);

        $this->assertSame(1, AuditLog::query()->where('event', AuditLog::EVENT_TABLE_REQUEST_CREATED)->count());
    }

    public function test_table_request_lifecycle_transitions_record_correct_events(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);

        $request = $this->createTableRequest($table, TableRequest::TYPE_CALL_WAITER);

        $this->actingAs($owner, 'web')->postJson("/api/v1/table-requests/{$request->id}/acknowledge")->assertOk();
        $this->actingAs($owner, 'web')->postJson("/api/v1/table-requests/{$request->id}/complete")->assertOk();

        $this->assertSame(1, AuditLog::query()->where('event', AuditLog::EVENT_TABLE_REQUEST_ACKNOWLEDGED)->count());
        $this->assertSame(1, AuditLog::query()->where('event', AuditLog::EVENT_TABLE_REQUEST_COMPLETED)->count());

        $completedLog = AuditLog::query()->where('event', AuditLog::EVENT_TABLE_REQUEST_COMPLETED)->first();
        $this->assertEquals([
            'previous_status' => TableRequest::STATUS_ACKNOWLEDGED,
            'new_status' => TableRequest::STATUS_COMPLETED,
            'type' => TableRequest::TYPE_CALL_WAITER,
        ], $completedLog->metadata);
    }

    public function test_table_request_cancel_via_endpoint_records_cancelled_event(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);

        $request = $this->createTableRequest($table, TableRequest::TYPE_CALL_WAITER);

        $this->actingAs($owner, 'web')->postJson("/api/v1/table-requests/{$request->id}/cancel")->assertOk();

        $log = AuditLog::query()->where('event', AuditLog::EVENT_TABLE_REQUEST_CANCELLED)->first();
        $this->assertNotNull($log);
        $this->assertSame($request->id, $log->resource_id);
        $this->assertArrayNotHasKey('reason', $log->metadata);
    }
}
