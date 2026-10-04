<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class EnforcedCsrfToken extends ValidateCsrfToken
{
    protected function runningUnitTests()
    {
        return false;
    }
}

class HttpSecurityBoundaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['sanctum.stateful' => ['localhost:5173'], 'sanctum.middleware.validate_csrf_token' => EnforcedCsrfToken::class, 'app.debug' => false]);
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    public function test_csrf_missing_invalid_and_authenticated_post_fail_closed(): void
    {
        $this->postJson('/api/v1/auth/login', ['email' => 'x@example.test', 'password' => 'x'])->assertStatus(419);
        $this->postJson('/api/v1/auth/login', [], ['X-CSRF-TOKEN' => 'invalid'])->assertStatus(419);
        $user = User::factory()->create();
        $this->actingAs($user, 'web')->postJson('/api/v1/auth/logout')->assertStatus(419);
        $this->assertAuthenticatedAs($user, 'web');
    }

    public function test_login_regenerates_session_and_logout_destroys_authenticated_state(): void
    {
        $user = User::factory()->create(['password' => 'password123']);
        $this->get('/sanctum/csrf-cookie')->assertNoContent();
        $session = app('session.store');
        $oldId = $session->getId();
        $csrf = $session->token();
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password123'], ['X-CSRF-TOKEN' => $csrf])->assertOk();
        $this->assertNotSame($oldId, $session->getId());
        Auth::forgetGuards();
        $this->getJson('/api/v1/auth/me')->assertOk();
        $loggedInId = $session->getId();
        $this->postJson('/api/v1/auth/logout', [], ['X-CSRF-TOKEN' => $session->token()])->assertNoContent();
        $this->assertNotSame($loggedInId, $session->getId());
        $this->assertNotSame($csrf, $session->token());
        Auth::forgetGuards();
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->assertSame('json', config('session.serialization'));
    }

    public function test_cors_exact_origin_matching_with_credentials(): void
    {
        config(['cors.allowed_origins' => ['https://app.example.test'], 'cors.allowed_origins_patterns' => []]);
        foreach (['https://app.example.test' => true, 'https://evil.example' => false, 'null' => false, '*' => false, 'https://app.example.test.evil.test' => false, 'https://sub.app.example.test' => false, 'https://app.example.test@evil.test' => false, 'https://app.example.test/path' => false, 'garbage' => false] as $origin => $allowed) {
            $response = $this->withHeaders(['Origin' => $origin, 'Access-Control-Request-Method' => 'POST'])->options('/api/v1/auth/login');
            if ($allowed) {
                $response->assertHeader('Access-Control-Allow-Origin', $origin)->assertHeader('Access-Control-Allow-Credentials', 'true');
            } else {
                $this->assertNotSame($origin, $response->headers->get('Access-Control-Allow-Origin'));
                $this->assertNotSame('*', $response->headers->get('Access-Control-Allow-Origin'));
            }
        }
    }

    public function test_debug_disabled_sanitizes_controlled_error_responses(): void
    {
        Route::middleware('api')->get('/api/security-controlled-error', fn () => throw new \RuntimeException('SELECT secret FROM /private/.env APP_KEY=fake-secret DB_HOST=private Meta_token=fake'));
        foreach ([403, 404, 422, 429] as $code) {
            Route::middleware('api')->get('/api/security-error-'.$code, fn () => abort($code));
        }
        foreach ([403, 404, 422, 429, 500] as $code) {
            $response = $this->getJson($code === 500 ? '/api/security-controlled-error' : '/api/security-error-'.$code)->assertStatus($code);
            foreach (['SELECT secret', '/private/', 'APP_KEY', 'DB_HOST', 'Meta_token', 'trace', 'exception', '/var/www'] as $secret) {
                $this->assertStringNotContainsString($secret, $response->getContent());
            }
        }
    }

    public function test_forwarded_headers_do_not_bypass_login_throttle(): void
    {
        // This test isolates throttling from CSRF using the normal testing middleware.
        config(['sanctum.middleware.validate_csrf_token' => ValidateCsrfToken::class]);
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', ['email' => 'target@example.test', 'password' => 'wrong'], ['X-Forwarded-For' => '198.51.100.'.($i + 1), 'Forwarded' => 'for=198.51.100.'.($i + 1)])->assertUnauthorized();
        }
        $this->postJson('/api/v1/auth/login', ['email' => ' TARGET@EXAMPLE.TEST ', 'password' => 'wrong'], ['X-Forwarded-For' => '198.51.100.99'])->assertTooManyRequests();
    }
}
