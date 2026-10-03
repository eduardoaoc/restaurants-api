<?php

namespace Tests\Feature\Platform;

use App\Models\AuditLog;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\Concerns\InteractsWithPlatform;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class PlatformAuditLogTest extends TestCase
{
    use InteractsWithPlatform, InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
        $this->seedPlatformRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    public function test_platform_audit_log_lists_platform_events_only(): void
    {
        $admin = $this->createPlatformAdmin();
        [$organization, $owner, $restaurant] = $this->createTenant();

        // One platform-level event...
        $this->actingAs($admin, 'web')
            ->patchJson("/api/v1/platform/organizations/{$organization->id}/status", [
                'status' => Organization::STATUS_SUSPENDED,
                'reason' => 'Isolation check.',
            ])
            ->assertOk();

        // ...and one tenant-level event, recorded independently.
        AuditLog::query()->create([
            'organization_id' => $organization->id,
            'restaurant_id' => $restaurant->id,
            'actor_user_id' => $owner->id,
            'actor_type' => AuditLog::ACTOR_USER,
            'event' => AuditLog::EVENT_STAFF_CREATED,
            'resource_type' => AuditLog::RESOURCE_STAFF,
            'resource_id' => $owner->id,
            'created_at' => now(),
        ]);

        $response = $this->actingAs($admin, 'web')
            ->getJson('/api/v1/platform/audit-logs')
            ->assertOk();

        $events = collect($response->json('data.audit_logs'))->pluck('event');

        $this->assertTrue($events->contains(AuditLog::EVENT_PLATFORM_ORGANIZATION_SUSPENDED));
        $this->assertFalse($events->contains(AuditLog::EVENT_STAFF_CREATED));
    }

    /**
     * The tenant audit log endpoint is unaffected: it lists only its own
     * organization's tenant events and never a platform.* event, even
     * though both live in the same audit_logs table now.
     */
    public function test_tenant_audit_log_never_lists_platform_events(): void
    {
        $admin = $this->createPlatformAdmin();
        [$organization, $owner] = $this->createTenant();

        $this->actingAs($admin, 'web')
            ->patchJson("/api/v1/platform/organizations/{$organization->id}/status", [
                'status' => Organization::STATUS_SUSPENDED,
                'reason' => 'Isolation check.',
            ])
            ->assertOk();

        $this->actingAs($admin, 'web')
            ->patchJson("/api/v1/platform/organizations/{$organization->id}/status", [
                'status' => Organization::STATUS_ACTIVE,
                'reason' => 'Reinstated so the owner can call the tenant endpoint.',
            ])
            ->assertOk();

        // Switching the acting identity mid-test, across a real HTTP
        // request boundary, needs a guard reset first — see
        // AuthenticationTest::test_user_cannot_access_me_after_logout for
        // the same workaround already established in this codebase.
        Auth::forgetGuards();

        $response = $this->actingAs($owner, 'web')
            ->getJson('/api/v1/audit-logs')
            ->assertOk();

        $events = collect($response->json('data.audit_logs'))->pluck('event');

        $this->assertFalse($events->contains(AuditLog::EVENT_PLATFORM_ORGANIZATION_SUSPENDED));
        $this->assertFalse($events->contains(AuditLog::EVENT_PLATFORM_ORGANIZATION_REACTIVATED));
    }
}
