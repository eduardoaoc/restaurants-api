<?php

namespace Tests\Feature\AuditLog;

use App\Actions\Tables\AssignWaiterAction;
use App\Actions\Tables\CallResponsibleWaiterAction;
use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithOrders;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bloco 4 — audit events for transfer and waiter calls.
 */
class AuditLogTableOperationalActionsTest extends TestCase
{
    use InteractsWithOrders, InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    public function test_transfer_records_a_single_aggregated_event(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $tableA = $this->createTable($restaurant);
        $tableB = $this->createTable($restaurant);
        $session = $this->openSession($tableA, $owner);

        $this->actingAs($owner, 'web')
            ->postJson("/api/v1/table-sessions/{$session->id}/transfer", ['target_table_id' => $tableB->id])
            ->assertOk();

        $this->assertSame(1, AuditLog::query()->where('event', AuditLog::EVENT_TABLE_SESSION_TRANSFERRED)->count());
    }

    public function test_waiter_call_records_called_event_with_waiter_and_session(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);
        app(AssignWaiterAction::class)->execute($session, $waiter, $owner);

        $this->actingAs($owner, 'web')
            ->postJson("/api/v1/table-sessions/{$session->id}/waiter-calls")
            ->assertCreated();

        $log = AuditLog::query()->where('event', AuditLog::EVENT_TABLE_SESSION_RESPONSIBLE_WAITER_CALLED)->first();
        $this->assertNotNull($log);
        $this->assertSame($owner->id, $log->actor_user_id);
        $this->assertSame($restaurant->id, $log->restaurant_id);
        $this->assertEquals([
            'table_session_id' => $session->id,
            'waiter_user_id' => $waiter->id,
        ], $log->metadata);
    }

    public function test_acknowledge_records_distinct_acknowledged_event(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);
        app(AssignWaiterAction::class)->execute($session, $waiter, $owner);
        $call = app(CallResponsibleWaiterAction::class)->execute($session, $owner);

        $this->actingAs($waiter, 'web')->postJson("/api/v1/waiter-calls/{$call->id}/acknowledge")->assertOk();

        $log = AuditLog::query()->where('event', AuditLog::EVENT_TABLE_SESSION_RESPONSIBLE_WAITER_CALL_ACKNOWLEDGED)->first();
        $this->assertNotNull($log);
        $this->assertSame($waiter->id, $log->actor_user_id);
        $this->assertSame($call->id, $log->resource_id);
    }
}
