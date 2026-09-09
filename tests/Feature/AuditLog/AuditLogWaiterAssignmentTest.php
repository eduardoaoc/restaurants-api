<?php

namespace Tests\Feature\AuditLog;

use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithOrders;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bloco 2 — waiter assignment audit events: assigned (from unassigned),
 * reassigned (replacing a previous waiter), unassigned. A harmless no-op
 * (same waiter re-sent, or unassigning an already-unassigned session)
 * records nothing.
 */
class AuditLogWaiterAssignmentTest extends TestCase
{
    use InteractsWithOrders, InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    public function test_assigning_an_unassigned_session_records_waiter_assigned(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');

        $this->actingAs($owner, 'web')
            ->putJson("/api/v1/table-sessions/{$session->id}/waiter", ['user_id' => $waiter->id])
            ->assertOk();

        $log = AuditLog::query()->where('event', AuditLog::EVENT_TABLE_SESSION_WAITER_ASSIGNED)->first();
        $this->assertNotNull($log);
        $this->assertSame($restaurant->id, $log->restaurant_id);
        $this->assertSame($organization->id, $log->organization_id);
        $this->assertSame(AuditLog::ACTOR_USER, $log->actor_type);
        $this->assertSame($owner->id, $log->actor_user_id);
        $this->assertSame($session->id, $log->resource_id);
        $this->assertEquals([
            'table_id' => $table->id,
            'previous_waiter_user_id' => null,
            'new_waiter_user_id' => $waiter->id,
        ], $log->metadata);

        $this->assertSame(0, AuditLog::query()->where('event', AuditLog::EVENT_TABLE_SESSION_WAITER_REASSIGNED)->count());
    }

    public function test_reassigning_a_different_waiter_records_waiter_reassigned(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);
        $mateo = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');
        $lucia = $this->createStaff($organization, $restaurant, 'waiter', 'W-2');

        $this->actingAs($owner, 'web')
            ->putJson("/api/v1/table-sessions/{$session->id}/waiter", ['user_id' => $mateo->id])
            ->assertOk();

        $this->actingAs($owner, 'web')
            ->putJson("/api/v1/table-sessions/{$session->id}/waiter", ['user_id' => $lucia->id])
            ->assertOk();

        $this->assertSame(1, AuditLog::query()->where('event', AuditLog::EVENT_TABLE_SESSION_WAITER_ASSIGNED)->count());

        $log = AuditLog::query()->where('event', AuditLog::EVENT_TABLE_SESSION_WAITER_REASSIGNED)->first();
        $this->assertNotNull($log);
        $this->assertEquals([
            'table_id' => $table->id,
            'previous_waiter_user_id' => $mateo->id,
            'new_waiter_user_id' => $lucia->id,
        ], $log->metadata);
    }

    public function test_reassigning_the_same_waiter_records_no_extra_event(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');

        $this->actingAs($owner, 'web')
            ->putJson("/api/v1/table-sessions/{$session->id}/waiter", ['user_id' => $waiter->id])
            ->assertOk();

        $this->actingAs($owner, 'web')
            ->putJson("/api/v1/table-sessions/{$session->id}/waiter", ['user_id' => $waiter->id])
            ->assertOk();

        $this->assertSame(1, AuditLog::query()->where('resource_id', $session->id)->whereIn('event', [
            AuditLog::EVENT_TABLE_SESSION_WAITER_ASSIGNED,
            AuditLog::EVENT_TABLE_SESSION_WAITER_REASSIGNED,
        ])->count());
    }

    public function test_unassign_records_waiter_unassigned(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');

        $this->actingAs($owner, 'web')
            ->putJson("/api/v1/table-sessions/{$session->id}/waiter", ['user_id' => $waiter->id])
            ->assertOk();

        $this->actingAs($owner, 'web')
            ->deleteJson("/api/v1/table-sessions/{$session->id}/waiter")
            ->assertOk();

        $log = AuditLog::query()->where('event', AuditLog::EVENT_TABLE_SESSION_WAITER_UNASSIGNED)->first();
        $this->assertNotNull($log);
        $this->assertSame($owner->id, $log->actor_user_id);
        $this->assertEquals([
            'table_id' => $table->id,
            'previous_waiter_user_id' => $waiter->id,
        ], $log->metadata);
    }

    public function test_unassigning_an_already_unassigned_session_records_no_event(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);

        $this->actingAs($owner, 'web')
            ->deleteJson("/api/v1/table-sessions/{$session->id}/waiter")
            ->assertOk();

        $this->assertSame(0, AuditLog::query()->where('event', AuditLog::EVENT_TABLE_SESSION_WAITER_UNASSIGNED)->count());
    }
}
