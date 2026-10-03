<?php

namespace Tests\Feature\AuditLog;

use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithStaffShifts;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bloco 3 — staff shift audit events, and the actor/subject distinction:
 * self action records actor_user_id === user_id; an admin action records
 * a different actor_user_id than the metadata's user_id.
 */
class AuditLogStaffShiftTest extends TestCase
{
    use InteractsWithStaffShifts, InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    public function test_self_start_records_matching_actor_and_user(): void
    {
        [$organization, , $restaurant] = $this->createTenant();
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');

        $this->actingAs($waiter, 'web')
            ->postJson("/api/v1/restaurants/{$restaurant->id}/staff-shifts", ['user_id' => $waiter->id])
            ->assertCreated();

        $log = AuditLog::query()->where('event', AuditLog::EVENT_STAFF_SHIFT_STARTED)->first();
        $this->assertNotNull($log);
        $this->assertSame($restaurant->id, $log->restaurant_id);
        $this->assertSame($organization->id, $log->organization_id);
        $this->assertSame(AuditLog::ACTOR_USER, $log->actor_type);
        $this->assertSame($waiter->id, $log->actor_user_id);
        $this->assertSame($waiter->id, $log->metadata['user_id']);
    }

    public function test_manager_starting_staff_shift_records_distinct_actor_and_user(): void
    {
        [$organization, , $restaurant] = $this->createTenant();
        $manager = $this->createStaff($organization, $restaurant, 'manager', 'M-1');
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');

        $this->actingAs($manager, 'web')
            ->postJson("/api/v1/restaurants/{$restaurant->id}/staff-shifts", ['user_id' => $waiter->id])
            ->assertCreated();

        $log = AuditLog::query()->where('event', AuditLog::EVENT_STAFF_SHIFT_STARTED)->first();
        $this->assertNotNull($log);
        $this->assertSame($manager->id, $log->actor_user_id);
        $this->assertSame($waiter->id, $log->metadata['user_id']);
        $this->assertNotSame($log->actor_user_id, $log->metadata['user_id']);
    }

    public function test_end_records_staff_shift_ended_event(): void
    {
        [$organization, , $restaurant] = $this->createTenant();
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');
        $shift = $this->startShift($restaurant, $waiter, $waiter);

        $this->actingAs($waiter, 'web')
            ->postJson("/api/v1/staff-shifts/{$shift->id}/end")
            ->assertOk();

        $log = AuditLog::query()->where('event', AuditLog::EVENT_STAFF_SHIFT_ENDED)->first();
        $this->assertNotNull($log);
        $this->assertSame($shift->id, $log->resource_id);
        $this->assertSame($waiter->id, $log->actor_user_id);
        $this->assertSame($restaurant->id, $log->restaurant_id);
        $this->assertNotNull($log->metadata['ended_at']);
    }

    public function test_manager_ending_staff_shift_records_distinct_actor(): void
    {
        [$organization, , $restaurant] = $this->createTenant();
        $manager = $this->createStaff($organization, $restaurant, 'manager', 'M-1');
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');
        $shift = $this->startShift($restaurant, $waiter, $waiter);

        $this->actingAs($manager, 'web')
            ->postJson("/api/v1/staff-shifts/{$shift->id}/end")
            ->assertOk();

        $log = AuditLog::query()->where('event', AuditLog::EVENT_STAFF_SHIFT_ENDED)->first();
        $this->assertNotNull($log);
        $this->assertSame($manager->id, $log->actor_user_id);
        $this->assertSame($waiter->id, $log->metadata['user_id']);
    }
}
