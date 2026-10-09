<?php

namespace Tests\Feature\Security;

use App\Http\Middleware\SetSecurityHeaders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_responses_carry_baseline_security_headers(): void
    {
        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeaderMissing('Strict-Transport-Security')
            ->assertHeaderMissing('X-Powered-By');
    }

    public function test_error_responses_carry_them_too(): void
    {
        $this->getJson('/api/v1/organization')
            ->assertUnauthorized()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'no-referrer');
    }

    public function test_hsts_is_sent_over_https_only(): void
    {
        $this->getJson('https://localhost/api/v1/health')
            ->assertOk()
            ->assertHeader('Strict-Transport-Security', SetSecurityHeaders::HSTS);
    }
}
