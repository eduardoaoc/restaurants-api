<?php

namespace Tests\Feature\Auth;

use App\Models\AuditLog;
use App\Models\Invitation;
use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\InteractsWithInvitations;
use Tests\Concerns\InteractsWithPlatform;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use InteractsWithInvitations, InteractsWithPlatform, InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
        config(['invitations.web_url' => 'http://localhost:5174']);
    }

    private function user(string $email = 'ana@example.com', string $password = 'old-password-1'): User
    {
        return User::factory()->create(['email' => $email, 'password' => $password]);
    }

    /** The clear token exactly as the e-mail link carries it. */
    private function tokenFromMail(User $user): string
    {
        $token = null;
        Notification::assertSentTo($user, ResetPasswordNotification::class, function (ResetPasswordNotification $notification) use ($user, &$token) {
            $url = $notification->toMail($user)->actionUrl;
            $this->assertStringStartsWith('http://localhost:5174/reset-password#', $url);
            parse_str(substr($url, strpos($url, '#') + 1), $fragment);
            $this->assertSame($user->email, $fragment['email']);
            $token = $fragment['token'];

            return true;
        });

        return $token;
    }

    private function reset(string $email, string $token, string $password = 'new-password-1'): TestResponse
    {
        return $this->postJson('/api/v1/auth/reset-password', [
            'email' => $email, 'token' => $token, 'password' => $password, 'password_confirmation' => $password,
        ]);
    }

    public function test_forgot_answers_identically_for_existing_missing_and_suspended_accounts(): void
    {
        Notification::fake();
        $active = $this->user();
        $suspended = $this->user('suspended@example.com');
        $suspended->update(['status' => User::STATUS_SUSPENDED]);

        $bodies = [];
        foreach (['ANA@example.com', 'nobody@example.com', 'suspended@example.com'] as $email) {
            $response = $this->postJson('/api/v1/auth/forgot-password', ['email' => $email])->assertOk();
            $bodies[] = $response->getContent();
        }

        $this->assertCount(1, array_unique($bodies));
        Notification::assertSentTo($active, ResetPasswordNotification::class);
        Notification::assertNotSentTo($suspended, ResetPasswordNotification::class);
        Notification::assertCount(1);

        // The broker stores only a hash of the token.
        $token = $this->tokenFromMail($active);
        $row = DB::table('password_reset_tokens')->where('email', 'ana@example.com')->sole();
        $this->assertNotSame($token, $row->token);
        $this->assertTrue(Hash::check($token, $row->token));
    }

    public function test_repeated_requests_within_the_broker_throttle_still_answer_the_same(): void
    {
        Notification::fake();
        $user = $this->user();

        $first = $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])->assertOk()->getContent();
        $second = $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])->assertOk()->getContent();

        $this->assertSame($first, $second);
        Notification::assertSentToTimes($user, ResetPasswordNotification::class, 1);
    }

    public function test_forgot_is_rate_limited_per_ip_without_revealing_accounts(): void
    {
        Notification::fake();
        $this->user();

        foreach (range(1, 5) as $i) {
            $this->postJson('/api/v1/auth/forgot-password', ['email' => "n{$i}@example.com"])->assertOk();
        }

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'ana@example.com'])->assertTooManyRequests();
        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'other@example.com'])->assertTooManyRequests();
    }

    public function test_forgot_is_rate_limited_per_e_mail_across_ips(): void
    {
        Notification::fake();

        foreach (range(1, 5) as $i) {
            $this->withServerVariables(['REMOTE_ADDR' => "10.0.0.{$i}"])
                ->postJson('/api/v1/auth/forgot-password', ['email' => 'target@example.com'])->assertOk();
        }

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.99'])
            ->postJson('/api/v1/auth/forgot-password', ['email' => 'Target@Example.com'])->assertTooManyRequests();
    }

    public function test_link_uses_trusted_configuration_not_the_request_host(): void
    {
        Notification::fake();
        $user = $this->user();

        $this->withHeader('Host', 'evil.example')->withHeader('X-Forwarded-Host', 'evil.example')
            ->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])->assertOk();

        $this->assertNotEmpty($this->tokenFromMail($user)); // asserts the localhost:5174 base
    }

    public function test_preview_does_not_consume_and_reset_requires_explicit_confirmation(): void
    {
        Notification::fake();
        $user = $this->user();
        $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email]);
        $token = $this->tokenFromMail($user);

        foreach ([1, 2] as $attempt) {
            $this->postJson('/api/v1/auth/reset-password/preview', ['email' => $user->email, 'token' => $token])
                ->assertOk()->assertJsonPath('data.valid', true);
        }

        $this->assertTrue(Hash::check('old-password-1', $user->fresh()->password));
        $this->assertSame(1, DB::table('password_reset_tokens')->count());

        $this->reset($user->email, $token)->assertOk();
        $this->assertTrue(Hash::check('new-password-1', $user->fresh()->password));
    }

    public function test_invalid_wrong_user_expired_and_used_tokens_are_indistinguishable(): void
    {
        Notification::fake();
        $user = $this->user();
        $other = $this->user('other@example.com');
        $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email]);
        $token = $this->tokenFromMail($user);

        $invalid = fn ($response) => $response->assertStatus(422)->assertJsonPath('error.code', 'PASSWORD_RESET_INVALID');

        $invalid($this->postJson('/api/v1/auth/reset-password/preview', ['email' => $user->email, 'token' => 'wrong']));
        $invalid($this->postJson('/api/v1/auth/reset-password/preview', ['email' => $other->email, 'token' => $token]));
        $invalid($this->postJson('/api/v1/auth/reset-password/preview', ['email' => 'nobody@example.com', 'token' => $token]));
        $invalid($this->reset($other->email, $token));
        $this->assertTrue(Hash::check('old-password-1', $other->fresh()->password));

        $this->reset($user->email, $token)->assertOk();
        $invalid($this->reset($user->email, $token, 'third-password-1')); // single use
        $this->assertTrue(Hash::check('new-password-1', $user->fresh()->password));

        $this->postJson('/api/v1/auth/forgot-password', ['email' => $other->email]);
        $otherToken = $this->tokenFromMail($other);
        $this->travel(61)->minutes();
        $invalid($this->postJson('/api/v1/auth/reset-password/preview', ['email' => $other->email, 'token' => $otherToken]));
        $invalid($this->reset($other->email, $otherToken));
    }

    public function test_password_policy_is_enforced(): void
    {
        Notification::fake();
        $user = $this->user();
        $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email]);
        $token = $this->tokenFromMail($user);

        $this->reset($user->email, $token, 'short')->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->postJson('/api/v1/auth/reset-password', ['email' => $user->email, 'token' => $token, 'password' => 'long-enough-1', 'password_confirmation' => 'different-1'])
            ->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->postJson('/api/v1/auth/reset-password/preview', ['email' => $user->email, 'token' => $token])->assertOk();
    }

    public function test_success_revokes_sessions_of_that_account_only_and_never_signs_in(): void
    {
        config(['session.driver' => 'database']);
        Notification::fake();
        $user = $this->user();
        $bystander = $this->user('bystander@example.com');
        foreach ([[$user->id, 'mine-1'], [$user->id, 'mine-2'], [$bystander->id, 'theirs']] as [$userId, $id]) {
            DB::table('sessions')->insert(['id' => $id, 'user_id' => $userId, 'ip_address' => '127.0.0.1', 'user_agent' => 'test', 'payload' => '', 'last_activity' => time()]);
        }
        $rememberBefore = $user->fresh()->remember_token;

        $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email]);
        $token = $this->tokenFromMail($user);
        $this->reset($user->email, $token)->assertOk()->assertJsonMissingPath('data.user');

        $this->assertGuest('web');
        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->id)->count());
        $this->assertSame(1, DB::table('sessions')->where('user_id', $bystander->id)->count());
        $this->assertNotSame($rememberBefore, $user->fresh()->remember_token);
        $this->assertSame(0, DB::table('password_reset_tokens')->count());

        // New password works through the normal login, old one does not.
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'old-password-1'])->assertUnauthorized();
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'new-password-1'])->assertOk();
    }

    public function test_reset_never_lifts_a_platform_suspension(): void
    {
        $user = $this->user();
        $token = Password::broker()->createToken($user); // issued before the suspension
        $user->update(['status' => User::STATUS_SUSPENDED]);

        $this->reset($user->email, $token)->assertOk();
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'new-password-1'])->assertForbidden();
        $this->assertSame(User::STATUS_SUSPENDED, $user->fresh()->status);
    }

    public function test_recovery_does_not_grant_organization_access_and_the_invitation_still_needs_acceptance(): void
    {
        Notification::fake();
        $this->seedPlatformRolesAndPermissions();
        [$otherOrganization, , $otherRestaurant] = $this->createTenant();
        $existing = $this->createStaff($otherOrganization, $otherRestaurant, 'waiter', 'W-1', 'pepe@example.com', 'Pepe Original');
        $admin = $this->createPlatformAdmin();
        $this->onboard($admin, $this->newOrganizationPayload())->assertCreated();
        $invitation = Invitation::query()->sole();
        $invitationToken = $this->mintToken($invitation);
        $this->freshBrowser();

        // Forgot the password → reset it.
        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'pepe@example.com'])->assertOk();
        $this->reset('pepe@example.com', $this->tokenFromMail($existing), 'brand-new-pass-1')->assertOk();

        $existing->refresh();
        $this->assertSame('Pepe Original', $existing->name);
        $this->assertFalse($existing->organizations()->whereKey($invitation->organization_id)->exists());
        $this->assertTrue($invitation->fresh()->isPending());

        // Someone else cannot use the invitation; the owner of the e-mail accepts with the NEW password.
        [, $intruder] = $this->createTenant();
        $this->actingAs($intruder, 'web');
        $this->acceptExisting($invitationToken, 'brand-new-pass-1')->assertStatus(409)->assertJsonPath('error.code', 'INVITATION_ACCOUNT_MISMATCH');
        $this->freshBrowser();
        $this->acceptExisting($invitationToken, 'password123')->assertUnprocessable();
        $this->acceptExisting($invitationToken, 'brand-new-pass-1')->assertOk();
        $this->assertTrue($existing->organizations()->whereKey($invitation->organization_id)->exists());
        $this->assertSame(1, $existing->organizations()->whereKey($otherOrganization->id)->count());
    }

    public function test_secrets_never_reach_the_application_log_or_audit(): void
    {
        // Real mail channel through the local capture transport.
        config(['mail.default' => 'log', 'mail.mailers.log.channel' => 'mail_capture']);
        $appLog = storage_path('logs/laravel.log');
        $captureLog = storage_path('logs/mail-capture.log');
        File::delete([$appLog, $captureLog]);
        $user = $this->user();

        $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])->assertOk();

        $capture = (string) File::get($captureLog);
        $this->assertStringContainsString('/reset-password#', $capture, 'the capture box holds the e-mail');
        preg_match('/token=([A-Za-z0-9]+)/', $capture, $match);
        $token = $match[1];
        $this->assertSame('0600', substr(sprintf('%o', fileperms($captureLog)), -4));

        $this->reset($user->email, $token, 'super-secret-new-1')->assertOk();

        $appContent = File::exists($appLog) ? (string) File::get($appLog) : '';
        $this->assertStringNotContainsString($token, $appContent);
        $this->assertStringNotContainsString('super-secret-new-1', $appContent);
        $audit = json_encode(AuditLog::query()->get()->toArray());
        $this->assertStringNotContainsString($token, $audit);
        $this->assertStringNotContainsString('super-secret-new-1', $audit);
        $this->assertSame(1, AuditLog::query()->where('event', AuditLog::EVENT_AUTH_PASSWORD_RESET)->count());
    }

    public function test_reset_e_mail_is_queued_encrypted_on_the_mail_queue(): void
    {
        config(['queue.default' => 'database', 'invitations.mail_queue' => 'mail-test']);
        $user = $this->user();

        $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])->assertOk();

        $job = DB::table('jobs')->sole();
        $this->assertSame('mail-test', $job->queue);
        $this->assertStringNotContainsString('reset-password#', $job->payload);
        $this->assertStringNotContainsString('ResetPasswordNotification', json_decode($job->payload, true)['data']['command']);
    }
}
