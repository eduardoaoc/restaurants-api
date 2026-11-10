<?php

namespace Tests\Feature\Invitations;

use App\Models\Invitation;
use App\Models\User;
use App\Notifications\InvitationNotification;
use App\Support\Invitations\InvitationTokens;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Tests\Concerns\InteractsWithInvitations;
use Tests\Concerns\InteractsWithPlatform;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class InvitationLifecycleTest extends TestCase
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

    private function staffInvitation(User $owner, int $restaurantId): Invitation
    {
        $this->actingAs($owner, 'web')->postJson('/api/v1/staff/invitations', [
            'name' => 'Ana', 'email' => 'ana@example.com', 'role' => 'waiter',
            'restaurant_assignments' => [['restaurant_id' => $restaurantId]],
        ])->assertCreated();

        return Invitation::query()->sole();
    }

    public function test_resend_respects_the_cooldown(): void
    {
        Notification::fake();
        [, $owner, $restaurant] = $this->createTenant();
        $invitation = $this->staffInvitation($owner, $restaurant->id);

        $this->postJson("/api/v1/staff/invitations/{$invitation->id}/resend")
            ->assertStatus(429)
            ->assertJsonPath('error.code', 'INVITATION_RESEND_TOO_SOON')
            ->assertHeader('Retry-After');

        $this->travel(61)->seconds();
        $this->postJson("/api/v1/staff/invitations/{$invitation->id}/resend")
            ->assertOk()->assertJsonPath('data.invitation.delivery.send_count', 2);

        Notification::assertSentTimes(InvitationNotification::class, 2);
        $this->assertSame(1, Invitation::query()->count());
    }

    public function test_resend_invalidates_the_previous_link_and_renews_expiry(): void
    {
        Notification::fake();
        [, $owner, $restaurant] = $this->createTenant();
        $invitation = $this->staffInvitation($owner, $restaurant->id);
        $oldToken = $this->mintToken($invitation);
        $oldExpiry = $invitation->fresh()->expires_at;

        $this->travel(80)->hours(); // already expired
        $this->postJson('/api/v1/invitations/preview', ['token' => $oldToken])->assertStatus(410);

        $this->postJson("/api/v1/staff/invitations/{$invitation->id}/resend")->assertOk()->assertJsonPath('data.invitation.state', 'pending');

        $fresh = $invitation->fresh();
        $this->assertTrue($fresh->expires_at->greaterThan($oldExpiry));
        $this->postJson('/api/v1/invitations/preview', ['token' => $oldToken])->assertNotFound();

        $newToken = $this->mintToken($fresh);
        $this->freshBrowser();
        $this->acceptNew($newToken)->assertOk();
        $this->assertSame(1, User::query()->where('email', 'ana@example.com')->count());
    }

    public function test_accepted_or_revoked_invitations_cannot_be_resent_or_revoked(): void
    {
        Notification::fake();
        [, $owner, $restaurant] = $this->createTenant();
        $invitation = $this->staffInvitation($owner, $restaurant->id);

        $this->postJson("/api/v1/staff/invitations/{$invitation->id}/revoke")->assertOk()->assertJsonPath('data.invitation.state', 'revoked');
        $this->postJson("/api/v1/staff/invitations/{$invitation->id}/revoke")->assertStatus(409)->assertJsonPath('error.code', 'INVITATION_NOT_PENDING');
        $this->travel(61)->seconds();
        $this->postJson("/api/v1/staff/invitations/{$invitation->id}/resend")->assertStatus(409)->assertJsonPath('error.code', 'INVITATION_NOT_PENDING');

        // A new invitation for the same person is possible after revoking.
        $this->postJson('/api/v1/staff/invitations', [
            'name' => 'Ana', 'email' => 'ana@example.com', 'role' => 'waiter',
            'restaurant_assignments' => [['restaurant_id' => $restaurant->id]],
        ])->assertCreated();
    }

    public function test_stale_queued_job_is_dropped_after_resend_revoke_or_acceptance(): void
    {
        Notification::fake();
        [, $owner, $restaurant] = $this->createTenant();
        $invitation = $this->staffInvitation($owner, $restaurant->id);
        $notifiable = new AnonymousNotifiable;

        $first = new InvitationNotification($invitation->id, 1, 'stale-token');
        $this->assertTrue($first->shouldSend($notifiable, 'mail'));

        $this->travel(61)->seconds();
        $this->postJson("/api/v1/staff/invitations/{$invitation->id}/resend")->assertOk();
        $this->assertFalse($first->shouldSend($notifiable, 'mail'), 'superseded by sequence 2');
        $second = new InvitationNotification($invitation->id, 2, 'current-token');
        $this->assertTrue($second->shouldSend($notifiable, 'mail'));

        $this->postJson("/api/v1/staff/invitations/{$invitation->id}/revoke")->assertOk();
        $this->assertFalse($second->shouldSend($notifiable, 'mail'), 'revoked');
    }

    public function test_queued_job_payload_is_encrypted_and_never_holds_a_clear_link(): void
    {
        // Real database queue (not fake): inspect what actually lands in `jobs`.
        config(['queue.default' => 'database', 'invitations.mail_queue' => 'mail-test']);
        [, $owner, $restaurant] = $this->createTenant();
        $invitation = $this->staffInvitation($owner, $restaurant->id);

        $job = DB::table('jobs')->sole();
        $this->assertSame('mail-test', $job->queue);
        $payload = json_decode($job->payload, true);
        $this->assertSame(InvitationNotification::class, $payload['displayName']);
        $command = $payload['data']['command'];
        $this->assertStringNotContainsString('InvitationNotification', $command, 'command is ciphertext, not a serialized object');
        $this->assertStringNotContainsString('invitation#token=', $job->payload);

        // The worker can decrypt it and the link matches the stored hash.
        $notification = unserialize(Crypt::decrypt($command))->notification;
        $url = $notification->toMail(new AnonymousNotifiable)->actionUrl;
        $token = rawurldecode(substr($url, strpos($url, '#token=') + 7));
        $this->assertSame($invitation->fresh()->token_hash, InvitationTokens::hash($token));
        $this->assertSame(Invitation::DELIVERY_QUEUED, $invitation->fresh()->delivery_status);
    }

    public function test_retries_and_duplicate_executions_send_the_same_valid_link(): void
    {
        Notification::fake();
        [, $owner, $restaurant] = $this->createTenant();
        $invitation = $this->staffInvitation($owner, $restaurant->id);
        $hashBefore = $invitation->fresh()->token_hash;

        $sent = null;
        Notification::assertSentTo(new AnonymousNotifiable, InvitationNotification::class, function (InvitationNotification $notification) use (&$sent) {
            $sent = $notification;

            return true;
        });

        // Building the message again (retry / duplicated job) is pure.
        $first = $sent->toMail(new AnonymousNotifiable)->actionUrl;
        $second = $sent->toMail(new AnonymousNotifiable)->actionUrl;
        $this->assertSame($first, $second);
        $this->assertSame($hashBefore, $invitation->fresh()->token_hash, 'retries never overwrite the current token');
        $this->assertTrue($sent->shouldSend(new AnonymousNotifiable, 'mail'));

        $token = rawurldecode(substr($first, strpos($first, '#token=') + 7));
        $this->freshBrowser();
        $this->postJson('/api/v1/invitations/preview', ['token' => $token])->assertOk();

        // After a resend the old job is dropped and its link is dead; the new one works.
        $this->travel(61)->seconds();
        $this->actingAs($owner, 'web')->postJson("/api/v1/staff/invitations/{$invitation->id}/resend")->assertOk();
        $this->assertFalse($sent->shouldSend(new AnonymousNotifiable, 'mail'));
        $this->freshBrowser();
        $this->postJson('/api/v1/invitations/preview', ['token' => $token])->assertNotFound();
    }

    public function test_delivery_states_queued_sent_failed_and_accepted_are_distinct(): void
    {
        [, $owner, $restaurant] = $this->createTenant();

        // queued: job created, nothing handed to the transport yet.
        Notification::fake();
        $invitation = $this->staffInvitation($owner, $restaurant->id);
        $this->assertSame(Invitation::DELIVERY_QUEUED, $invitation->fresh()->delivery_status);
        $this->getJson('/api/v1/staff/invitations')->assertJsonPath('data.invitations.0.delivery.status', 'queued');

        // failed: the job's last attempt failed.
        (new InvitationNotification($invitation->id, 1, 'any-token'))->failed(new RuntimeException('smtp down'));
        $this->assertSame(Invitation::DELIVERY_FAILED, $invitation->fresh()->delivery_status);
        $this->assertNotNull($invitation->fresh()->delivery_failed_at);

        // sent: the transport accepted the resend (array mailer, real channel).
        Notification::swap(new ChannelManager($this->app));
        $this->travel(61)->seconds();
        $this->postJson("/api/v1/staff/invitations/{$invitation->id}/resend")
            ->assertOk()
            ->assertJsonPath('data.invitation.delivery.status', 'sent')
            ->assertJsonPath('data.invitation.delivery.failed_at', null)
            ->assertJsonPath('data.invitation.state', 'pending');

        // A late failure of the OLD job must not overwrite the newer "sent".
        (new InvitationNotification($invitation->id, 1, 'any-token'))->failed(new RuntimeException('late'));
        $this->assertSame(Invitation::DELIVERY_SENT, $invitation->fresh()->delivery_status);

        // accepted is a separate axis from delivery.
        $token = $this->mintToken($invitation);
        $this->freshBrowser();
        $this->acceptNew($token)->assertOk();
        $this->assertSame(Invitation::STATUS_ACCEPTED, $invitation->fresh()->state());
    }

    public function test_preview_and_accept_are_rate_limited(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/v1/invitations/preview', ['token' => 'same-token'])->assertNotFound();
        }

        $this->postJson('/api/v1/invitations/preview', ['token' => 'same-token'])->assertTooManyRequests();

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/invitations/accept-existing', ['token' => 'guess', 'password' => 'x'])->assertNotFound();
        }

        $this->postJson('/api/v1/invitations/accept-existing', ['token' => 'guess', 'password' => 'x'])->assertTooManyRequests();
    }

    public function test_platform_can_reinvite_owner_only_while_the_organization_has_no_active_owner(): void
    {
        Notification::fake();
        $admin = $this->createPlatformAdmin();
        $this->onboard($admin, $this->newOrganizationPayload())->assertCreated();
        $first = Invitation::query()->sole();
        $organizationId = $first->organization_id;

        // Pending first invitation: a second one is refused, pointing to it.
        $this->postJson("/api/v1/platform/organizations/{$organizationId}/owner-invitations", ['email' => 'right@example.com'])
            ->assertStatus(409)->assertJsonPath('error.code', 'INVITATION_ALREADY_PENDING')->assertJsonPath('error.invitation_id', $first->id);

        // Typo recovery: revoke, invite the right address.
        $this->postJson("/api/v1/platform/invitations/{$first->id}/revoke")->assertOk();
        $this->postJson("/api/v1/platform/organizations/{$organizationId}/owner-invitations", ['email' => 'Right@Example.com'])
            ->assertCreated()->assertJsonPath('data.invitation.email', 'right@example.com');

        $second = Invitation::query()->latest('id')->first();
        $token = $this->mintToken($second);
        $this->freshBrowser();
        $this->acceptNew($token)->assertOk();

        // Now there is an active owner: no co-owner/transfer through here.
        $this->freshBrowser();
        $this->actingAs($admin, 'web')->postJson("/api/v1/platform/organizations/{$organizationId}/owner-invitations", ['email' => 'third@example.com'])
            ->assertStatus(409)->assertJsonPath('error.code', 'ORGANIZATION_HAS_OWNER');

        $this->getJson("/api/v1/platform/invitations?organization_id={$organizationId}")
            ->assertOk()->assertJsonCount(2, 'data.invitations')
            ->assertJsonPath('data.invitations.0.state', 'accepted')
            ->assertJsonPath('data.invitations.1.state', 'revoked');
    }

    public function test_platform_invitation_endpoints_ignore_staff_invitations_and_need_permission(): void
    {
        Notification::fake();
        [, $owner, $restaurant] = $this->createTenant();
        $staffInvitation = $this->staffInvitation($owner, $restaurant->id);
        $admin = $this->createPlatformAdmin();

        $this->freshBrowser();
        $this->actingAs($admin, 'web')->postJson("/api/v1/platform/invitations/{$staffInvitation->id}/revoke")->assertNotFound();
        $this->getJson('/api/v1/platform/invitations')->assertOk()->assertJsonCount(0, 'data.invitations');

        $this->freshBrowser();
        $this->actingAs($owner, 'web')->getJson('/api/v1/platform/invitations')->assertForbidden();
    }
}
