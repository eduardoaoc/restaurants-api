<?php

namespace Tests\Feature\Invitations;

use App\Models\AuditLog;
use App\Models\Invitation;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\PlatformRole;
use App\Models\PlatformRoleAssignment;
use App\Models\User;
use App\Models\UserRole;
use App\Notifications\InvitationNotification;
use App\Support\Invitations\InvitationTokens;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\InteractsWithInvitations;
use Tests\Concerns\InteractsWithPlatform;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class InvitationAcceptanceTest extends TestCase
{
    use InteractsWithInvitations, InteractsWithPlatform, InteractsWithTenants, RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
        $this->seedPlatformRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
        config(['invitations.web_url' => 'http://localhost:5173']);
        Notification::fake();
        $this->admin = $this->createPlatformAdmin();
    }

    /** Onboards "Casa Pepe", then continues as a fresh browser (no admin session). */
    private function ownerInvitation(array $overrides = []): Invitation
    {
        $this->onboard($this->admin, $this->newOrganizationPayload($overrides))->assertCreated();
        $this->freshBrowser();

        return Invitation::query()->latest('id')->firstOrFail();
    }

    public function test_e_mail_link_uses_the_trusted_url_with_the_token_in_the_fragment(): void
    {
        $invitation = $this->ownerInvitation();

        Notification::assertSentTo(new AnonymousNotifiable, InvitationNotification::class, function (InvitationNotification $notification, $channels, $notifiable) {
            $mail = $notification->toMail($notifiable);
            $this->assertStringStartsWith('http://localhost:5173/invitation#token=', $mail->actionUrl);
            $this->assertStringNotContainsString('?', $mail->actionUrl);

            return true;
        });

        // The link carries the token issued at creation: only its hash is stored.
        Notification::assertSentTo(new AnonymousNotifiable, InvitationNotification::class, function (InvitationNotification $notification, $channels, $notifiable) use ($invitation) {
            $url = $notification->toMail($notifiable)->actionUrl;
            $this->assertSame($invitation->fresh()->token_hash, InvitationTokens::hash(rawurldecode(substr($url, strpos($url, '#token=') + 7))));

            return true;
        });
    }

    public function test_preview_shows_the_invitation_and_does_not_consume_it(): void
    {
        $invitation = $this->ownerInvitation();
        $token = $this->mintToken($invitation);

        foreach ([1, 2] as $attempt) {
            $this->postJson('/api/v1/invitations/preview', ['token' => $token])
                ->assertOk()
                ->assertJsonPath('data.invitation.type', 'owner')
                ->assertJsonPath('data.invitation.email', 'pepe@example.com')
                ->assertJsonPath('data.invitation.organization.name', 'Casa Pepe SL')
                ->assertJsonPath('data.invitation.restaurants.0.name', 'Casa Pepe Ruzafa')
                ->assertJsonPath('data.invitation.account_exists', false)
                ->assertJsonMissingPath('data.invitation.id')
                ->assertJsonMissingPath('data.invitation.organization.id');
        }

        $this->assertTrue($invitation->fresh()->isPending());
        $this->assertGuest('web');
    }

    public function test_new_owner_defines_own_password_and_lands_in_the_right_context(): void
    {
        $invitation = $this->ownerInvitation();
        $token = $this->mintToken($invitation);

        $this->acceptNew($token, 'Pepe García', 'my-own-password')
            ->assertOk()
            ->assertJsonPath('data.user.email', 'pepe@example.com')
            ->assertJsonPath('data.organization_id', $invitation->organization_id)
            ->assertJsonMissingPath('data.user.password');

        $user = User::query()->where('email', 'pepe@example.com')->sole();
        $this->assertSame('Pepe García', $user->name);
        $this->assertTrue(Hash::check('my-own-password', $user->password));
        $this->assertNotNull($user->email_verified_at);
        $this->assertAuthenticatedAs($user, 'web');

        $grant = UserRole::query()->where('user_id', $user->id)->sole();
        $this->assertSame('owner', $grant->role->slug);
        $this->assertNull($grant->restaurant_id);
        $this->assertSame($invitation->organization_id, $grant->organization_id);

        $fresh = $invitation->fresh();
        $this->assertSame(Invitation::STATUS_ACCEPTED, $fresh->status);
        $this->assertSame($user->id, $fresh->accepted_user_id);

        $this->sameBrowserNextRequest();
        $context = $this->getJson('/api/v1/auth/context')->assertOk();
        $context->assertJsonPath('data.active_organization_id', $invitation->organization_id)
            ->assertJsonCount(1, 'data.organizations')
            ->assertJsonPath('data.organizations.0.roles', ['owner'])
            ->assertJsonPath('data.platform.is_platform_admin', false);
        $this->assertContains('manage_users', $context->json('data.organizations.0.restaurants.0.permissions'));

        $this->getJson('/api/v1/restaurants')->assertOk()->assertJsonCount(1, 'data.restaurants');
    }

    public function test_token_is_single_use(): void
    {
        $token = $this->mintToken($this->ownerInvitation());
        $this->acceptNew($token)->assertOk();

        $this->freshBrowser();

        $this->acceptNew($token)->assertNotFound()->assertJsonPath('error.code', 'INVITATION_NOT_FOUND');
        $this->postJson('/api/v1/invitations/preview', ['token' => $token])->assertNotFound();
        $this->assertSame(1, User::query()->where('email', 'pepe@example.com')->count());
    }

    public function test_expired_invitation_is_refused_clearly(): void
    {
        $token = $this->mintToken($this->ownerInvitation());
        $this->travel(73)->hours();

        $this->postJson('/api/v1/invitations/preview', ['token' => $token])->assertStatus(410)->assertJsonPath('error.code', 'INVITATION_EXPIRED');
        $this->acceptNew($token)->assertStatus(410)->assertJsonPath('error.code', 'INVITATION_EXPIRED');
        $this->assertSame(0, User::query()->where('email', 'pepe@example.com')->count());
    }

    public function test_revoked_invitation_is_refused(): void
    {
        $invitation = $this->ownerInvitation();
        $token = $this->mintToken($invitation);

        $this->actingAs($this->admin, 'web')->postJson("/api/v1/platform/invitations/{$invitation->id}/revoke")
            ->assertOk()->assertJsonPath('data.invitation.state', 'revoked');
        $this->freshBrowser();

        // Revocation also replaced the stored hash: the old link is dead.
        $this->acceptNew($token)->assertNotFound();
        $this->assertSame(0, User::query()->where('email', 'pepe@example.com')->count());
    }

    public function test_unknown_or_malformed_tokens_reveal_nothing(): void
    {
        $this->postJson('/api/v1/invitations/preview', ['token' => 'nope'])->assertNotFound()->assertJsonPath('error.code', 'INVITATION_NOT_FOUND');
        $this->postJson('/api/v1/invitations/preview', ['token' => str_repeat('a', 500)])->assertUnprocessable();
        $this->postJson('/api/v1/invitations/preview', [])->assertUnprocessable();
    }

    public function test_existing_account_keeps_password_and_identity(): void
    {
        [$otherOrganization, , $otherRestaurant] = $this->createTenant();
        $existing = $this->createStaff($otherOrganization, $otherRestaurant, 'waiter', 'W-1', 'pepe@example.com', 'Pepe Original');
        $originalHash = $existing->password;

        $invitation = $this->ownerInvitation(['owner' => ['name' => 'Nombre Distinto']]);
        $token = $this->mintToken($invitation);

        $this->postJson('/api/v1/invitations/preview', ['token' => $token])->assertJsonPath('data.invitation.account_exists', true);

        // The new-account path cannot be used to take over the account.
        $this->acceptNew($token, 'Hijacker', 'attacker-password')
            ->assertStatus(409)->assertJsonPath('error.code', 'INVITATION_ACCOUNT_EXISTS');
        $this->acceptExisting($token, 'wrong-password')->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->assertGuest('web');

        $this->acceptExisting($token, 'password123')->assertOk();

        $existing->refresh();
        $this->assertSame('Pepe Original', $existing->name);
        $this->assertSame($originalHash, $existing->password);
        $this->assertAuthenticatedAs($existing, 'web');

        // Both memberships, nothing leaked from one into the other.
        $this->assertSame(2, $existing->organizations()->count());
        $ownerGrant = UserRole::query()->where('user_id', $existing->id)->where('organization_id', $invitation->organization_id)->sole();
        $this->assertSame('owner', $ownerGrant->role->slug);
        $waiterGrant = UserRole::query()->where('user_id', $existing->id)->where('organization_id', $otherOrganization->id)->sole();
        $this->assertSame('waiter', $waiterGrant->role->slug);
        $this->assertSame($otherRestaurant->id, $waiterGrant->restaurant_id);

        $this->sameBrowserNextRequest();
        $context = $this->getJson('/api/v1/auth/context')->assertOk()
            ->assertJsonPath('data.active_organization_id', $invitation->organization_id);
        $organizations = collect($context->json('data.organizations'))->keyBy('id');
        $this->assertSame([], $organizations[$otherOrganization->id]['roles']);
        $this->assertSame(['waiter'], $organizations[$otherOrganization->id]['restaurants'][0]['roles']);
        $this->assertSame(['owner'], $organizations[$invitation->organization_id]['roles']);
        $this->assertNotContains('manage_users', $organizations[$otherOrganization->id]['restaurants'][0]['permissions']);
    }

    public function test_existing_account_path_requires_an_account(): void
    {
        $token = $this->mintToken($this->ownerInvitation());

        $this->acceptExisting($token, 'whatever')->assertStatus(409)->assertJsonPath('error.code', 'INVITATION_ACCOUNT_REQUIRED');
    }

    public function test_a_different_signed_in_user_cannot_accept(): void
    {
        $invitation = $this->ownerInvitation();
        $token = $this->mintToken($invitation);
        [, $intruder] = $this->createTenant();

        $this->actingAs($intruder, 'web');
        $this->acceptNew($token)->assertStatus(409)->assertJsonPath('error.code', 'INVITATION_ACCOUNT_MISMATCH');
        $this->acceptExisting($token, 'password')->assertStatus(409)->assertJsonPath('error.code', 'INVITATION_ACCOUNT_MISMATCH');

        $this->assertTrue($invitation->fresh()->isPending());
        $this->assertFalse($intruder->organizations()->whereKey($invitation->organization_id)->exists());
    }

    public function test_the_signed_in_recipient_still_confirms_the_password(): void
    {
        [$otherOrganization, , $otherRestaurant] = $this->createTenant();
        $existing = $this->createStaff($otherOrganization, $otherRestaurant, 'waiter', 'W-1', 'pepe@example.com');
        $token = $this->mintToken($this->ownerInvitation());

        $this->actingAs($existing, 'web');
        $this->acceptExisting($token, 'not-it')->assertUnprocessable();
        $this->acceptExisting($token, 'password123')->assertOk();
    }

    public function test_suspended_account_cannot_accept(): void
    {
        [$otherOrganization, , $otherRestaurant] = $this->createTenant();
        $existing = $this->createStaff($otherOrganization, $otherRestaurant, 'waiter', 'W-1', 'pepe@example.com');
        $existing->update(['status' => User::STATUS_SUSPENDED]);
        $invitation = $this->ownerInvitation();

        $this->acceptExisting($this->mintToken($invitation), 'password123')
            ->assertForbidden()->assertJsonPath('error.code', 'INVITATION_ACCOUNT_SUSPENDED');
        $this->assertTrue($invitation->fresh()->isPending());
        $this->assertGuest('web');
    }

    public function test_suspended_organization_cannot_be_joined(): void
    {
        $invitation = $this->ownerInvitation();
        Organization::query()->whereKey($invitation->organization_id)->update(['status' => Organization::STATUS_SUSPENDED]);

        $this->acceptNew($this->mintToken($invitation))->assertStatus(409)->assertJsonPath('error.code', 'INVITATION_ORGANIZATION_UNAVAILABLE');
        $this->assertSame(0, User::query()->where('email', 'pepe@example.com')->count());
    }

    public function test_platform_admin_account_cannot_accept_an_owner_invitation(): void
    {
        // Created before the address became a platform account.
        $invitation = $this->ownerInvitation(['owner' => ['email' => 'later-admin@example.com']]);
        $later = User::factory()->create(['email' => 'later-admin@example.com', 'password' => 'password123']);
        $this->createPlatformAdminFor($later);

        $this->acceptExisting($this->mintToken($invitation), 'password123')
            ->assertStatus(409)->assertJsonPath('error.code', 'INVITATION_NOT_ALLOWED_FOR_ACCOUNT');
        $this->assertFalse($later->organizations()->exists());
    }

    public function test_accept_validates_password_rules_without_side_effects(): void
    {
        $invitation = $this->ownerInvitation();
        $token = $this->mintToken($invitation);

        $this->postJson('/api/v1/invitations/accept', ['token' => $token, 'name' => 'X', 'password' => 'short', 'password_confirmation' => 'short'])
            ->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->postJson('/api/v1/invitations/accept', ['token' => $token, 'name' => 'X', 'password' => 'long-enough-1', 'password_confirmation' => 'different-1'])
            ->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->assertTrue($invitation->fresh()->isPending());
    }

    public function test_client_cannot_choose_the_account_e_mail(): void
    {
        $token = $this->mintToken($this->ownerInvitation());

        $this->postJson('/api/v1/invitations/accept', [
            'token' => $token, 'name' => 'X', 'email' => 'attacker@example.com',
            'password' => 'secret-pass-123', 'password_confirmation' => 'secret-pass-123',
        ])->assertOk()->assertJsonPath('data.user.email', 'pepe@example.com');

        $this->assertSame(0, User::query()->where('email', 'attacker@example.com')->count());
    }

    public function test_acceptance_is_audited_without_secrets(): void
    {
        $invitation = $this->ownerInvitation();
        $token = $this->mintToken($invitation);
        $this->acceptNew($token, 'Pepe', 'secret-pass-123')->assertOk();

        $log = AuditLog::query()->where('event', AuditLog::EVENT_INVITATION_ACCEPTED)->sole();
        $this->assertSame($invitation->organization_id, $log->organization_id);
        $this->assertSame('owner', $log->metadata['type']);
        $this->assertSame('new', $log->metadata['account']);

        $dump = json_encode(AuditLog::query()->get()->toArray());
        $this->assertStringNotContainsString($token, $dump);
        $this->assertStringNotContainsString('secret-pass-123', $dump);
        $this->assertStringNotContainsString('pepe@example.com', $dump);
    }

    public function test_inactive_membership_is_never_reactivated_by_an_invitation(): void
    {
        $invitation = $this->ownerInvitation();
        $user = User::factory()->create(['email' => 'pepe@example.com', 'password' => 'password123']);
        OrganizationUser::query()->create(['organization_id' => $invitation->organization_id, 'user_id' => $user->id, 'status' => OrganizationUser::STATUS_INACTIVE]);

        $this->acceptExisting($this->mintToken($invitation), 'password123')
            ->assertStatus(409)->assertJsonPath('error.code', 'INVITATION_MEMBERSHIP_CONFLICT');
        $this->assertSame(OrganizationUser::STATUS_INACTIVE, OrganizationUser::query()->where('user_id', $user->id)->value('status'));
        $this->assertSame(0, UserRole::query()->where('user_id', $user->id)->count());
    }

    private function createPlatformAdminFor(User $user): void
    {
        $role = PlatformRole::query()->where('slug', 'super_admin')->firstOrFail();
        PlatformRoleAssignment::query()->create(['user_id' => $user->id, 'platform_role_id' => $role->id]);
    }
}
