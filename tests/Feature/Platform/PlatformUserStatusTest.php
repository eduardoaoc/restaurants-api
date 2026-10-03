<?php

namespace Tests\Feature\Platform;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithPlatform;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class PlatformUserStatusTest extends TestCase
{
    use InteractsWithPlatform, InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
        $this->seedPlatformRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    public function test_super_admin_suspends_a_user(): void
    {
        $admin = $this->createPlatformAdmin();
        [, $owner] = $this->createTenant();

        $response = $this->actingAs($admin, 'web')
            ->patchJson("/api/v1/platform/users/{$owner->id}/status", [
                'status' => User::STATUS_SUSPENDED,
                'reason' => 'Reported abusive behavior — ticket #482.',
            ])
            ->assertOk();

        $response->assertJsonPath('data.user.status', User::STATUS_SUSPENDED);
        $this->assertSame(User::STATUS_SUSPENDED, $owner->fresh()->status);

        $this->assertDatabaseHas('audit_logs', [
            'event' => AuditLog::EVENT_PLATFORM_USER_SUSPENDED,
            'actor_type' => AuditLog::ACTOR_PLATFORM_ADMIN,
            'actor_user_id' => $admin->id,
            'resource_type' => AuditLog::RESOURCE_USER,
            'resource_id' => $owner->id,
        ]);

        $log = AuditLog::query()->where('event', AuditLog::EVENT_PLATFORM_USER_SUSPENDED)->firstOrFail();
        $this->assertSame('Reported abusive behavior — ticket #482.', $log->metadata['reason']);
        $this->assertSame(User::STATUS_ACTIVE, $log->changes['status']['old']);
        $this->assertSame(User::STATUS_SUSPENDED, $log->changes['status']['new']);
    }

    public function test_suspending_a_user_deletes_their_active_sessions(): void
    {
        $admin = $this->createPlatformAdmin();
        [, $owner] = $this->createTenant();

        DB::table('sessions')->insert([
            'id' => 'session-under-test',
            'user_id' => $owner->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'test',
            'payload' => base64_encode('irrelevant'),
            'last_activity' => now()->timestamp,
        ]);

        $this->actingAs($admin, 'web')
            ->patchJson("/api/v1/platform/users/{$owner->id}/status", [
                'status' => User::STATUS_SUSPENDED,
                'reason' => 'Session invalidation check.',
            ])
            ->assertOk();

        $this->assertDatabaseMissing('sessions', ['user_id' => $owner->id]);
    }

    public function test_super_admin_reactivates_a_suspended_user(): void
    {
        $admin = $this->createPlatformAdmin();
        [, $owner] = $this->createTenant();
        $owner->update(['status' => User::STATUS_SUSPENDED]);

        $this->actingAs($admin, 'web')
            ->patchJson("/api/v1/platform/users/{$owner->id}/status", [
                'status' => User::STATUS_ACTIVE,
                'reason' => 'Ticket resolved, reinstating access.',
            ])
            ->assertOk()
            ->assertJsonPath('data.user.status', User::STATUS_ACTIVE);

        $this->assertSame(User::STATUS_ACTIVE, $owner->fresh()->status);

        $this->assertDatabaseHas('audit_logs', [
            'event' => AuditLog::EVENT_PLATFORM_USER_REACTIVATED,
            'resource_id' => $owner->id,
        ]);
    }

    public function test_reason_is_required_to_change_user_status(): void
    {
        $admin = $this->createPlatformAdmin();
        [, $owner] = $this->createTenant();

        $this->actingAs($admin, 'web')
            ->patchJson("/api/v1/platform/users/{$owner->id}/status", [
                'status' => User::STATUS_SUSPENDED,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reason');
    }

    public function test_super_admin_cannot_suspend_their_own_account(): void
    {
        $admin = $this->createPlatformAdmin();

        $this->actingAs($admin, 'web')
            ->patchJson("/api/v1/platform/users/{$admin->id}/status", [
                'status' => User::STATUS_SUSPENDED,
                'reason' => 'Testing self-suspend guard.',
            ])
            ->assertForbidden();

        $this->assertSame(User::STATUS_ACTIVE, $admin->fresh()->status);
    }

    public function test_platform_user_endpoints_never_expose_password_or_remember_token(): void
    {
        $admin = $this->createPlatformAdmin();
        [, $owner] = $this->createTenant();

        $this->actingAs($admin, 'web')
            ->getJson('/api/v1/platform/users')
            ->assertOk()
            ->assertJsonMissingPath('data.users.0.password')
            ->assertJsonMissingPath('data.users.0.remember_token');

        $this->actingAs($admin, 'web')
            ->getJson("/api/v1/platform/users/{$owner->id}")
            ->assertOk()
            ->assertJsonMissingPath('data.user.password')
            ->assertJsonMissingPath('data.user.remember_token');
    }

    public function test_suspended_user_receives_403_on_tenant_routes_even_with_an_existing_session(): void
    {
        [$organization, $owner] = $this->createTenant();
        $owner->update(['status' => User::STATUS_SUSPENDED]);

        $this->actingAs($owner, 'web')
            ->getJson('/api/v1/organization')
            ->assertForbidden();
    }
}
