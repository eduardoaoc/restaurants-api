<?php

namespace Tests\Feature\Platform;

use App\Models\AuditLog;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithPlatform;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class PlatformOrganizationPlanTest extends TestCase
{
    use InteractsWithPlatform, InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
        $this->seedPlatformRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    public function test_super_admin_changes_an_organizations_plan(): void
    {
        $admin = $this->createPlatformAdmin();
        [$organization] = $this->createTenant();

        $this->actingAs($admin, 'web')
            ->patchJson("/api/v1/platform/organizations/{$organization->id}/plan", [
                'plan' => Organization::PLAN_PRO,
                'reason' => 'Manually upgraded after support ticket #501.',
            ])
            ->assertOk()
            ->assertJsonPath('data.organization.plan', Organization::PLAN_PRO);

        $this->assertSame(Organization::PLAN_PRO, $organization->fresh()->plan);

        $this->assertDatabaseHas('audit_logs', [
            'event' => AuditLog::EVENT_PLATFORM_ORGANIZATION_PLAN_CHANGED,
            'organization_id' => $organization->id,
        ]);
    }

    public function test_super_admin_corrects_a_subscription_status(): void
    {
        $admin = $this->createPlatformAdmin();
        [$organization] = $this->createTenant();

        $this->actingAs($admin, 'web')
            ->patchJson("/api/v1/platform/organizations/{$organization->id}/plan", [
                'subscription_status' => Organization::SUBSCRIPTION_STATUS_PAST_DUE,
                'reason' => 'Card declined, flagging for follow-up.',
            ])
            ->assertOk()
            ->assertJsonPath('data.organization.subscription_status', Organization::SUBSCRIPTION_STATUS_PAST_DUE);

        $this->assertSame(Organization::SUBSCRIPTION_STATUS_PAST_DUE, $organization->fresh()->subscription_status);
    }

    public function test_plan_or_subscription_status_is_required(): void
    {
        $admin = $this->createPlatformAdmin();
        [$organization] = $this->createTenant();

        $this->actingAs($admin, 'web')
            ->patchJson("/api/v1/platform/organizations/{$organization->id}/plan", [
                'reason' => 'No actual change attached.',
            ])
            ->assertUnprocessable();
    }

    public function test_reason_is_required_to_change_plan(): void
    {
        $admin = $this->createPlatformAdmin();
        [$organization] = $this->createTenant();

        $this->actingAs($admin, 'web')
            ->patchJson("/api/v1/platform/organizations/{$organization->id}/plan", [
                'plan' => Organization::PLAN_STARTER,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reason');
    }

    public function test_organization_defaults_to_free_plan_and_active_subscription(): void
    {
        [$organization] = $this->createTenant();

        // create() only returns what was explicitly inserted — plan/
        // subscription_status come from the column's DB-level default
        // (see the migration), so the in-memory model must be refreshed
        // to see them.
        $organization = $organization->fresh();

        $this->assertSame(Organization::PLAN_FREE, $organization->plan);
        $this->assertSame(Organization::SUBSCRIPTION_STATUS_ACTIVE, $organization->subscription_status);
    }
}
