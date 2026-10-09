<?php

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * With the database session driver every cookieless request that starts a
 * session inserts a `sessions` row — an anonymous flood would grow that
 * table without bound.
 */
class SessionCreationThrottleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['session.driver' => 'database']);
    }

    public function test_the_root_url_is_404_and_starts_no_session(): void
    {
        $this->get('/')->assertNotFound();
        $this->get('/up')->assertOk();

        $this->assertSame(0, DB::table('sessions')->count());
    }

    public function test_csrf_cookie_still_issues_the_xsrf_cookie(): void
    {
        $this->get('/sanctum/csrf-cookie')
            ->assertNoContent()
            ->assertCookie('XSRF-TOKEN');
    }

    public function test_csrf_cookie_is_throttled_per_ip(): void
    {
        $cacheKey = md5('csrf-cookie'.'127.0.0.1');
        RateLimiter::clear($cacheKey);
        for ($i = 0; $i < 60; $i++) {
            RateLimiter::hit($cacheKey, 60);
        }

        $this->get('/sanctum/csrf-cookie')->assertTooManyRequests();

        RateLimiter::clear($cacheKey);
    }
}
