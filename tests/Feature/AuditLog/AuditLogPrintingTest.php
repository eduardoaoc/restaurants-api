<?php

namespace Tests\Feature\AuditLog;

use App\Models\AuditLog;
use App\Models\PrintRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithOrders;
use Tests\Concerns\InteractsWithPayments;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class AuditLogPrintingTest extends TestCase
{
    use InteractsWithOrders, InteractsWithPayments, InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    public function test_kitchen_ticket_print_records_audit_event_and_preview_does_not(): void
    {
        [$organization, $owner, $restaurant, $restaurantProduct] = $this->createTenantWithRestaurantProduct();
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);
        $order = $this->createWaiterOrder($table, $owner, [
            ['restaurant_product_id' => $restaurantProduct->id, 'quantity' => 1],
        ]);

        $this->actingAs($owner, 'web')->getJson("/api/v1/orders/{$order->id}/kitchen-ticket")->assertOk();
        $this->assertSame(0, AuditLog::query()->where('event', AuditLog::EVENT_PRINT_RECORD_CREATED)->count());

        $this->actingAs($owner, 'web')->postJson("/api/v1/orders/{$order->id}/kitchen-ticket/print")->assertCreated();

        $log = AuditLog::query()->where('event', AuditLog::EVENT_PRINT_RECORD_CREATED)->first();
        $this->assertNotNull($log);
        $this->assertEquals([
            'document_type' => PrintRecord::DOCUMENT_TYPE_KITCHEN_TICKET,
            'order_id' => $order->id,
            'table_session_id' => $order->table_session_id,
        ], $log->metadata);
    }

    public function test_kitchen_ticket_reprint_records_a_second_audit_event(): void
    {
        [$organization, $owner, $restaurant, $restaurantProduct] = $this->createTenantWithRestaurantProduct();
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);
        $order = $this->createWaiterOrder($table, $owner, [
            ['restaurant_product_id' => $restaurantProduct->id, 'quantity' => 1],
        ]);

        $this->actingAs($owner, 'web')->postJson("/api/v1/orders/{$order->id}/kitchen-ticket/print")->assertCreated();
        $this->actingAs($owner, 'web')->postJson("/api/v1/orders/{$order->id}/kitchen-ticket/print")->assertCreated();

        $this->assertSame(2, AuditLog::query()->where('event', AuditLog::EVENT_PRINT_RECORD_CREATED)->count());
        $this->assertSame(2, PrintRecord::query()->count());
    }

    public function test_bill_receipt_print_records_audit_event_and_preview_does_not(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);

        $this->actingAs($owner, 'web')->getJson("/api/v1/table-sessions/{$session->id}/receipt")->assertOk();
        $this->assertSame(0, AuditLog::query()->where('event', AuditLog::EVENT_PRINT_RECORD_CREATED)->count());

        $this->actingAs($owner, 'web')->postJson("/api/v1/table-sessions/{$session->id}/receipt/print")->assertCreated();

        $log = AuditLog::query()->where('event', AuditLog::EVENT_PRINT_RECORD_CREATED)->first();
        $this->assertNotNull($log);
        $this->assertEquals([
            'document_type' => PrintRecord::DOCUMENT_TYPE_BILL_RECEIPT,
            'order_id' => null,
            'table_session_id' => $session->id,
        ], $log->metadata);
    }
}
