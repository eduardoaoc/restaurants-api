<?php

namespace Tests\Feature\AuditLog;

use App\Models\AuditLog;
use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class AuditLogStaffTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    public function test_staff_created_records_one_audit_event_with_correct_fields(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();

        $this->actingAs($owner, 'web')->postJson('/api/v1/staff', [
            'name' => 'Carlos',
            'email' => 'carlos-'.uniqid().'@example.com',
            'password' => 'password123',
            'restaurant_id' => $restaurant->id,
            'role' => 'waiter',
            'sub_id' => 'W-1',
        ])->assertCreated();

        $this->assertSame(1, AuditLog::query()->where('event', AuditLog::EVENT_STAFF_CREATED)->count());

        $log = AuditLog::query()->where('event', AuditLog::EVENT_STAFF_CREATED)->first();
        $this->assertSame($organization->id, $log->organization_id);
        $this->assertSame($restaurant->id, $log->restaurant_id);
        $this->assertSame($owner->id, $log->actor_user_id);
        $this->assertSame(AuditLog::ACTOR_USER, $log->actor_type);
        $this->assertSame(AuditLog::RESOURCE_STAFF, $log->resource_type);
        $this->assertEquals(['restaurant_id' => $restaurant->id], $log->metadata);
    }

    public function test_staff_updated_records_name_change(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $staff = $this->createStaff($organization, $restaurant, 'waiter', 'W-1', name: 'Carlos');

        $this->actingAs($owner, 'web')
            ->patchJson("/api/v1/staff/{$staff->id}", ['name' => 'Carlos Silva'])
            ->assertOk();

        $log = AuditLog::query()->where('event', AuditLog::EVENT_STAFF_UPDATED)->first();
        $this->assertNotNull($log);
        $this->assertSame(AuditLog::RESOURCE_STAFF, $log->resource_type);
        $this->assertSame($staff->id, $log->resource_id);
        $this->assertEquals(['name' => ['old' => 'Carlos', 'new' => 'Carlos Silva']], $log->changes);
    }

    public function test_no_op_staff_update_records_no_audit_event(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $staff = $this->createStaff($organization, $restaurant, 'waiter', 'W-1', name: 'Carlos');

        $this->actingAs($owner, 'web')
            ->patchJson("/api/v1/staff/{$staff->id}", ['name' => 'Carlos'])
            ->assertOk();

        $this->assertSame(0, AuditLog::query()->where('event', AuditLog::EVENT_STAFF_UPDATED)->count());
    }

    public function test_staff_update_never_puts_email_in_changes(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $staff = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');

        $this->actingAs($owner, 'web')
            ->patchJson("/api/v1/staff/{$staff->id}", [
                'name' => 'Renamed',
                'email' => 'new-email-'.uniqid().'@example.com',
            ])
            ->assertOk();

        $log = AuditLog::query()->where('event', AuditLog::EVENT_STAFF_UPDATED)->first();
        $this->assertArrayNotHasKey('email', $log->changes);
    }

    public function test_cross_restaurant_forbidden_staff_access_records_no_audit_event(): void
    {
        [$organization, , $restaurantA] = $this->createTenant();
        $restaurantB = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $managerA = $this->createStaff($organization, $restaurantA, 'manager', 'M-A');
        $staffB = $this->createStaff($organization, $restaurantB, 'waiter', 'W-B');

        $this->actingAs($managerA, 'web')
            ->patchJson("/api/v1/staff/{$staffB->id}", ['name' => 'Hacked'])
            ->assertNotFound();

        $this->assertSame(0, AuditLog::query()->where('event', AuditLog::EVENT_STAFF_UPDATED)->count());
    }
}
