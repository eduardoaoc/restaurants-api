<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeader('Origin', 'http://localhost:5173');
    }

    public function test_user_can_log_in_with_valid_credentials(): void
    {
        $user = User::factory()->create([
            'email' => 'user@example.com',
            'password' => 'password123',
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password123',
        ]);

        $response
            ->assertOk()
            ->assertJsonStructure([
                'message',
                'data' => [
                    'user' => ['id', 'name', 'email'],
                ],
            ])
            ->assertJsonPath('message', 'Authenticated successfully.')
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.user.email', $user->email)
            ->assertJsonMissingPath('data.user.password')
            ->assertJsonMissingPath('data.user.remember_token');

        $this->assertAuthenticatedAs($user, 'web');
    }

    public function test_user_cannot_log_in_with_an_incorrect_password(): void
    {
        User::factory()->create([
            'email' => 'user@example.com',
            'password' => 'password123',
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'user@example.com',
            'password' => 'incorrect-password',
        ])
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Invalid credentials.']);

        $this->assertGuest('web');
    }

    public function test_nonexistent_user_receives_the_same_invalid_credentials_response(): void
    {
        $this->postJson('/api/v1/auth/login', [
            'email' => 'missing@example.com',
            'password' => 'password123',
        ])
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Invalid credentials.']);

        $this->assertGuest('web');
    }

    public function test_email_is_required_for_login(): void
    {
        $this->postJson('/api/v1/auth/login', ['password' => 'password123'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    public function test_email_must_be_valid_for_login(): void
    {
        $this->postJson('/api/v1/auth/login', [
            'email' => 'not-an-email',
            'password' => 'password123',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    public function test_password_is_required_for_login(): void
    {
        $this->postJson('/api/v1/auth/login', ['email' => 'user@example.com'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');
    }

    public function test_remember_must_be_boolean_for_login(): void
    {
        $this->postJson('/api/v1/auth/login', [
            'email' => 'user@example.com',
            'password' => 'password123',
            'remember' => 'invalid',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('remember');
    }

    public function test_email_is_normalized_before_login(): void
    {
        $user = User::factory()->create([
            'email' => 'user@example.com',
            'password' => 'password123',
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => ' USER@EXAMPLE.COM ',
            'password' => 'password123',
        ])
            ->assertOk()
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.user.email', 'user@example.com')
            ->assertJsonMissingPath('data.user.password')
            ->assertJsonMissingPath('data.user.remember_token');

        $this->assertAuthenticatedAs($user, 'web');
    }

    public function test_unauthenticated_user_cannot_access_me(): void
    {
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_authenticated_user_can_access_me(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'web')
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.user.email', $user->email)
            ->assertJsonMissingPath('data.user.password')
            ->assertJsonMissingPath('data.user.remember_token');
    }

    public function test_authenticated_user_can_log_out(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'web')
            ->postJson('/api/v1/auth/logout')
            ->assertNoContent();

        $this->assertGuest('web');
    }

    public function test_user_cannot_access_me_after_logout(): void
    {
        $user = User::factory()->create([
            'email' => 'user@example.com',
            'password' => 'password123',
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password123',
        ])->assertOk();

        Auth::forgetGuards();
        $this->getJson('/api/v1/auth/me')->assertOk();
        $this->postJson('/api/v1/auth/logout')->assertNoContent();
        Auth::forgetGuards();
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->assertGuest('web');
    }

    public function test_login_is_rate_limited_after_five_attempts_per_minute(): void
    {
        $email = 'rate-limit@example.com';
        $limiterKey = $email.'|127.0.0.1';

        RateLimiter::clear($limiterKey);

        try {
            for ($attempt = 1; $attempt <= 5; $attempt++) {
                $this->postJson('/api/v1/auth/login', [
                    'email' => $email,
                    'password' => 'incorrect-password',
                ])->assertUnauthorized();
            }

            $this->postJson('/api/v1/auth/login', [
                'email' => $email,
                'password' => 'incorrect-password',
            ])->assertTooManyRequests();
        } finally {
            RateLimiter::clear($limiterKey);
        }
    }
}
