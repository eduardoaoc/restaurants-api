<?php

namespace Tests\Feature\Platform;

use App\Models\AuditLog;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\Concerns\InteractsWithPlatform;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class PlatformOrganizationStatusTest extends TestCase
{
    use InteractsWithPlatform, InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
        $this->seedPlatformRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    public function test_super_admin_suspends_an_organization(): void
    {
        $admin = $this->createPlatformAdmin();
        [$organization] = $this->createTenant();

        $this->actingAs($admin, 'web')
            ->patchJson("/api/v1/platform/organizations/{$organization->id}/status", [
                'status' => Organization::STATUS_SUSPENDED,
                'reason' => 'Payment failed for 3 consecutive cycles.',
            ])
            ->assertOk()
            ->assertJsonPath('data.organization.status', Organization::STATUS_SUSPENDED);

        $this->assertSame(Organization::STATUS_SUSPENDED, $organization->fresh()->status);

        $this->assertDatabaseHas('audit_logs', [
            'event' => AuditLog::EVENT_PLATFORM_ORGANIZATION_SUSPENDED,
            'actor_type' => AuditLog::ACTOR_PLATFORM_ADMIN,
            'organization_id' => $organization->id,
            'resource_type' => AuditLog::RESOURCE_ORGANIZATION,
            'resource_id' => $organization->id,
        ]);
    }

    /**
     * The core semantics of suspension: every tenant route becomes
     * unreachable, including the owner's own GET/PATCH /organization — an
     * owner cannot see the suspension reason nor un-suspend themselves.
     */
    public function test_suspended_organization_blocks_every_tenant_route_for_its_own_owner(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $organization->update(['status' => Organization::STATUS_SUSPENDED]);

        $this->actingAs($owner, 'web')
            ->getJson('/api/v1/organization')
            ->assertForbidden();

        $this->actingAs($owner, 'web')
            ->patchJson('/api/v1/organization', ['status' => 'active'])
            ->assertForbidden();

        $this->actingAs($owner, 'web')
            ->getJson('/api/v1/restaurants')
            ->assertForbidden();

        $this->actingAs($owner, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}")
            ->assertForbidden();
    }

    public function test_super_admin_reactivates_a_suspended_organization_and_tenant_access_returns(): void
    {
        $admin = $this->createPlatformAdmin();
        [$organization, $owner] = $this->createTenant();
        $organization->update(['status' => Organization::STATUS_SUSPENDED]);

        $this->actingAs($admin, 'web')
            ->patchJson("/api/v1/platform/organizations/{$organization->id}/status", [
                'status' => Organization::STATUS_ACTIVE,
                'reason' => 'Payment received, reinstating.',
            ])
            ->assertOk()
            ->assertJsonPath('data.organization.status', Organization::STATUS_ACTIVE);

        $this->assertDatabaseHas('audit_logs', [
            'event' => AuditLog::EVENT_PLATFORM_ORGANIZATION_REACTIVATED,
            'organization_id' => $organization->id,
        ]);

        // Switching the acting identity mid-test, across a real HTTP
        // request boundary, needs a guard reset first — see
        // AuthenticationTest::test_user_cannot_access_me_after_logout for
        // the same workaround already established in this codebase.
        Auth::forgetGuards();

        $this->actingAs($owner, 'web')
            ->getJson('/api/v1/organization')
            ->assertOk();
    }

    public function test_reason_is_required_to_change_organization_status(): void
    {
        $admin = $this->createPlatformAdmin();
        [$organization] = $this->createTenant();

        $this->actingAs($admin, 'web')
            ->patchJson("/api/v1/platform/organizations/{$organization->id}/status", [
                'status' => Organization::STATUS_SUSPENDED,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reason');
    }

    public function test_tenant_facing_organization_update_rejects_the_suspended_status_value(): void
    {
        [$organization, $owner] = $this->createTenant();

        $this->actingAs($owner, 'web')
            ->patchJson('/api/v1/organization', ['status' => 'suspended'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');
    }
}
