<?php

namespace Tests\Feature\Invitations;

use App\Models\AuditLog;
use App\Models\Invitation;
use App\Models\Organization;
use App\Models\PlatformPermission;
use App\Models\PlatformRole;
use App\Models\Restaurant;
use App\Models\RestaurantDailyOperation;
use App\Models\RestaurantSettings;
use App\Models\Table;
use App\Models\User;
use App\Models\UserRole;
use App\Notifications\InvitationNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\InteractsWithInvitations;
use Tests\Concerns\InteractsWithPlatform;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class PlatformOnboardingTest extends TestCase
{
    use InteractsWithInvitations, InteractsWithPlatform, InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
        $this->seedPlatformRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
        config(['invitations.web_url' => 'http://localhost:5173']);
    }

    public function test_super_admin_onboards_a_restaurant_in_a_new_organization(): void
    {
        Notification::fake();
        $admin = $this->createPlatformAdmin();

        $response = $this->onboard($admin, $this->newOrganizationPayload());

        $response->assertCreated()
            ->assertJsonPath('data.organization.created', true)
            ->assertJsonPath('data.organization.slug', 'casa-pepe')
            ->assertJsonPath('data.restaurant.timezone', RestaurantSettings::DEFAULT_TIMEZONE)
            ->assertJsonPath('data.restaurant.currency', 'EUR')
            ->assertJsonPath('data.restaurant.default_locale', 'es-ES')
            ->assertJsonPath('data.owner_invitation.email', 'pepe@example.com')
            ->assertJsonPath('data.owner_invitation.state', 'pending')
            ->assertJsonPath('data.owner_invitation.role', 'owner')
            ->assertJsonPath('data.owner_invitation.delivery.status', 'queued')
            ->assertJsonPath('data.replayed', false)
            ->assertJsonMissingPath('data.owner_invitation.token_hash');

        $organization = Organization::query()->where('slug', 'casa-pepe')->firstOrFail();
        $this->assertSame(Organization::PLAN_FREE, $organization->plan);
        $this->assertSame(Organization::SUBSCRIPTION_STATUS_ACTIVE, $organization->subscription_status);

        $restaurant = Restaurant::query()->where('organization_id', $organization->id)->sole();
        $this->assertNotNull($restaurant->settings);

        // Nothing operational, nothing granted, admin never joins.
        $this->assertSame(0, Table::query()->count());
        $this->assertSame(0, RestaurantDailyOperation::query()->count());
        $this->assertSame(0, UserRole::query()->where('organization_id', $organization->id)->count());
        $this->assertSame(0, $organization->users()->count());
        $this->assertFalse($admin->organizations()->exists());
        $this->assertSame(0, User::query()->where('email', 'pepe@example.com')->count());

        Notification::assertSentTo(new AnonymousNotifiable, InvitationNotification::class, function ($notification, $channels, $notifiable) {
            return $notifiable->routes['mail'] === 'pepe@example.com';
        });
    }

    public function test_restaurant_settings_accept_timezone_and_locale_overrides(): void
    {
        Notification::fake();
        $admin = $this->createPlatformAdmin();

        $this->onboard($admin, $this->newOrganizationPayload(['restaurant' => ['timezone' => 'Atlantic/Canary', 'default_locale' => 'ca-ES-valencia']]))
            ->assertCreated()
            ->assertJsonPath('data.restaurant.timezone', 'Atlantic/Canary')
            ->assertJsonPath('data.restaurant.default_locale', 'ca-ES-valencia');

        $this->assertSame('ca-ES-valencia', Invitation::query()->sole()->locale);
    }

    public function test_additional_restaurant_in_existing_organization_creates_no_invitation(): void
    {
        Notification::fake();
        $admin = $this->createPlatformAdmin();
        [$organization, $owner] = $this->createTenant();

        $response = $this->onboard($admin, [
            'organization_id' => $organization->id,
            'restaurant' => ['name' => 'Segundo local', 'slug' => 'segundo-local'],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.organization.id', $organization->id)
            ->assertJsonPath('data.organization.created', false)
            ->assertJsonPath('data.owner_invitation', null);

        $this->assertSame(0, Invitation::query()->count());
        Notification::assertNothingSent();

        // The existing org-wide owner reaches the new restaurant at once.
        $restaurantId = $response->json('data.restaurant.id');
        $this->app['auth']->forgetGuards();
        $this->actingAs($owner, 'web')->getJson("/api/v1/restaurants/{$restaurantId}")->assertOk();
    }

    public function test_existing_organization_mode_requires_organization_management_permission(): void
    {
        $role = PlatformRole::query()->create(['slug' => 'onboarding_only', 'name' => 'Onboarding']);
        $role->permissions()->sync(PlatformPermission::query()->where('slug', 'create_platform_restaurants')->pluck('id'));
        $admin = $this->createPlatformAdmin('onboarding_only');
        [$organization] = $this->createTenant();

        $this->onboard($admin, ['organization_id' => $organization->id, 'restaurant' => ['name' => 'X', 'slug' => 'x']])
            ->assertForbidden();
        $this->assertSame(1, Restaurant::query()->count());

        Notification::fake();
        $this->onboard($admin, $this->newOrganizationPayload(), 'other-key')->assertCreated();
    }

    public function test_suspended_existing_organization_is_refused(): void
    {
        $admin = $this->createPlatformAdmin();
        [$organization] = $this->createTenant();
        $organization->update(['status' => Organization::STATUS_SUSPENDED]);

        $this->onboard($admin, ['organization_id' => $organization->id, 'restaurant' => ['name' => 'X', 'slug' => 'x']])
            ->assertStatus(409)->assertJsonPath('error.code', 'ORGANIZATION_SUSPENDED');
    }

    public function test_tenant_owner_cannot_use_platform_onboarding(): void
    {
        [, $owner] = $this->createTenant();

        $this->onboard($owner, $this->newOrganizationPayload())->assertForbidden();
        $this->assertSame(0, Invitation::query()->count());
        $this->assertSame(1, Organization::query()->count());
    }

    public function test_guest_cannot_use_platform_onboarding(): void
    {
        $this->withHeader('Idempotency-Key', 'k')->postJson('/api/v1/platform/restaurants', $this->newOrganizationPayload())
            ->assertUnauthorized();
    }

    public function test_payload_cannot_inject_grants_tenant_or_billing(): void
    {
        Notification::fake();
        $admin = $this->createPlatformAdmin();
        [$otherOrganization] = $this->createTenant();

        $this->onboard($admin, $this->newOrganizationPayload([
            'organization' => ['status' => 'suspended', 'plan' => 'enterprise', 'subscription_status' => 'past_due', 'id' => $otherOrganization->id],
            'restaurant' => ['organization_id' => $otherOrganization->id, 'status' => 'suspended'],
            'owner' => ['role' => 'super_admin', 'password' => 'chosen-by-admin', 'permissions' => ['manage_platform_users']],
            'role' => 'owner',
            'restaurant_assignments' => [['restaurant_id' => 1]],
        ]))->assertCreated();

        $organization = Organization::query()->where('slug', 'casa-pepe')->firstOrFail();
        $this->assertNotSame($otherOrganization->id, $organization->id);
        $this->assertSame('active', $organization->status);
        $this->assertSame('free', $organization->plan);
        $this->assertSame('active', $organization->subscription_status);
        $restaurant = Restaurant::query()->where('slug', 'casa-pepe-ruzafa')->sole();
        $this->assertSame($organization->id, $restaurant->organization_id);
        $this->assertSame('active', $restaurant->status);
        $this->assertSame('owner', Invitation::query()->sole()->role->slug);
        $this->assertSame(0, User::query()->where('email', 'pepe@example.com')->count());
    }

    public function test_both_organization_modes_at_once_are_rejected(): void
    {
        $admin = $this->createPlatformAdmin();
        [$organization] = $this->createTenant();

        $this->onboard($admin, $this->newOrganizationPayload(['organization_id' => $organization->id]))
            ->assertUnprocessable();
    }

    public function test_owner_is_required_for_a_new_organization_and_prohibited_for_an_existing_one(): void
    {
        $admin = $this->createPlatformAdmin();
        [$organization] = $this->createTenant();

        $payload = $this->newOrganizationPayload();
        unset($payload['owner']);
        $this->onboard($admin, $payload)->assertUnprocessable()->assertJsonValidationErrors('owner');

        $this->onboard($admin, ['organization_id' => $organization->id, 'restaurant' => ['name' => 'X', 'slug' => 'x'], 'owner' => ['email' => 'a@b.com']], 'k2')
            ->assertUnprocessable();
    }

    public function test_idempotency_key_is_required(): void
    {
        $admin = $this->createPlatformAdmin();

        $this->actingAs($admin, 'web')->postJson('/api/v1/platform/restaurants', $this->newOrganizationPayload())
            ->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');
    }

    public function test_retry_with_same_key_replays_without_duplicating(): void
    {
        Notification::fake();
        $admin = $this->createPlatformAdmin();
        $payload = $this->newOrganizationPayload();

        $first = $this->onboard($admin, $payload, 'same-key')->assertCreated();
        $second = $this->onboard($admin, $payload, 'same-key')->assertOk()->assertJsonPath('data.replayed', true);

        $this->assertSame($first->json('data.restaurant.id'), $second->json('data.restaurant.id'));
        $this->assertSame($first->json('data.owner_invitation.id'), $second->json('data.owner_invitation.id'));
        $this->assertSame(1, Organization::query()->count());
        $this->assertSame(1, Restaurant::query()->count());
        $this->assertSame(1, Invitation::query()->count());
        Notification::assertSentTimes(InvitationNotification::class, 1);
    }

    public function test_same_key_with_different_payload_conflicts(): void
    {
        Notification::fake();
        $admin = $this->createPlatformAdmin();

        $this->onboard($admin, $this->newOrganizationPayload(), 'same-key')->assertCreated();
        $this->onboard($admin, $this->newOrganizationPayload(['restaurant' => ['name' => 'Otro nombre']]), 'same-key')
            ->assertStatus(409)->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_REUSED');

        $this->assertSame(1, Restaurant::query()->count());
    }

    public function test_new_key_with_same_slugs_is_a_readable_conflict(): void
    {
        Notification::fake();
        $admin = $this->createPlatformAdmin();

        $this->onboard($admin, $this->newOrganizationPayload(), 'key-a')->assertCreated();
        $this->onboard($admin, $this->newOrganizationPayload(), 'key-b')
            ->assertUnprocessable()->assertJsonValidationErrors('organization.slug');
    }

    public function test_platform_admin_account_cannot_be_the_owner(): void
    {
        $admin = $this->createPlatformAdmin();
        $otherAdmin = $this->createPlatformAdmin();

        $this->onboard($admin, $this->newOrganizationPayload(['owner' => ['email' => strtoupper($otherAdmin->email)]]))
            ->assertUnprocessable()->assertJsonValidationErrors('owner.email');
        $this->onboard($admin, $this->newOrganizationPayload(['owner' => ['email' => $admin->email]]), 'k2')
            ->assertUnprocessable()->assertJsonValidationErrors('owner.email');

        $this->assertSame(0, Organization::query()->count());
    }

    public function test_mail_failure_keeps_onboarding_complete_and_recoverable_by_resend(): void
    {
        $admin = $this->createPlatformAdmin();
        config(['invitations.web_url' => null]); // the e-mail cannot be built

        $response = $this->onboard($admin, $this->newOrganizationPayload())->assertCreated()
            ->assertJsonPath('data.owner_invitation.delivery.status', 'failed');

        $this->assertSame(1, Restaurant::query()->count());
        $invitation = Invitation::query()->sole();
        $this->assertSame(Invitation::DELIVERY_FAILED, $invitation->delivery_status);

        // Recovery: resend the same invitation — no second organization.
        config(['invitations.web_url' => 'http://localhost:5173']);
        $this->travel(61)->seconds();
        $this->actingAs($admin, 'web')->postJson("/api/v1/platform/invitations/{$invitation->id}/resend")
            ->assertOk()
            ->assertJsonPath('data.invitation.id', $invitation->id)
            ->assertJsonPath('data.invitation.delivery.status', 'sent')
            ->assertJsonPath('data.invitation.delivery.send_count', 2);

        $this->assertSame(1, Organization::query()->count());
        $this->assertSame(1, Restaurant::query()->count());
        $this->assertSame($response->json('data.restaurant.id'), Restaurant::query()->sole()->id);
    }

    public function test_successful_transport_marks_the_invitation_sent_never_accepted(): void
    {
        $admin = $this->createPlatformAdmin();

        $this->onboard($admin, $this->newOrganizationPayload())->assertCreated()
            ->assertJsonPath('data.owner_invitation.delivery.status', 'sent')
            ->assertJsonPath('data.owner_invitation.state', 'pending');

        $invitation = Invitation::query()->sole();
        $this->assertNotNull($invitation->last_sent_at);
        $this->assertNull($invitation->accepted_at);
    }

    public function test_audit_records_onboarding_without_secrets(): void
    {
        $admin = $this->createPlatformAdmin();

        $this->onboard($admin, $this->newOrganizationPayload())->assertCreated();

        $invitation = Invitation::query()->sole();
        $logs = AuditLog::query()->whereIn('event', [AuditLog::EVENT_PLATFORM_RESTAURANT_ONBOARDED, AuditLog::EVENT_INVITATION_CREATED])->get();
        $this->assertCount(2, $logs);
        $this->assertTrue($logs->every(fn (AuditLog $log) => $log->actor_type === AuditLog::ACTOR_PLATFORM_ADMIN));

        $dump = strtolower(json_encode(AuditLog::query()->get()->toArray()));
        $this->assertStringNotContainsString('pepe@example.com', $dump);
        $this->assertStringNotContainsString($invitation->token_hash, $dump);
        $this->assertStringNotContainsString('token', $dump);
    }
}
