<?php

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class AuthenticatedThrottleTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
    }

    /**
     * Prefill a named limiter's bucket instead of issuing hundreds of
     * requests — same approach as WebhookAndThrottleTest.
     */
    private function exhaust(string $limiter, string $key, int $maximum): string
    {
        $cacheKey = md5($limiter.$key);
        RateLimiter::clear($cacheKey);

        for ($i = 0; $i < $maximum; $i++) {
            RateLimiter::hit($cacheKey, 60);
        }

        return $cacheKey;
    }

    public function test_tenant_routes_are_throttled_per_user(): void
    {
        [, $owner] = $this->createTenant();
        $cacheKey = $this->exhaust('api', 'user:'.$owner->id, 300);

        $this->actingAs($owner, 'web')->getJson('/api/v1/organization')
            ->assertTooManyRequests()
            ->assertHeader('Retry-After');

        RateLimiter::clear($cacheKey);
        $this->actingAs($owner, 'web')->getJson('/api/v1/organization')->assertOk();
    }

    public function test_one_users_bucket_does_not_affect_another_user(): void
    {
        [, $owner] = $this->createTenant();
        [, $otherOwner] = $this->createTenant();
        $cacheKey = $this->exhaust('api', 'user:'.$owner->id, 300);

        $this->actingAs($otherOwner, 'web')->getJson('/api/v1/organization')->assertOk();

        RateLimiter::clear($cacheKey);
    }

    public function test_auth_context_and_platform_routes_are_throttled(): void
    {
        [, $owner] = $this->createTenant();
        $cacheKey = $this->exhaust('api', 'user:'.$owner->id, 300);

        $this->actingAs($owner, 'web')->getJson('/api/v1/auth/me')->assertTooManyRequests();
        $this->actingAs($owner, 'web')->getJson('/api/v1/auth/context')->assertTooManyRequests();
        $this->actingAs($owner, 'web')->getJson('/api/v1/platform/users')->assertTooManyRequests();
        $this->actingAs($owner, 'web')->postJson('/broadcasting/auth', [
            'socket_id' => '1.1',
            'channel_name' => 'private-user.'.$owner->id,
        ])->assertTooManyRequests();

        RateLimiter::clear($cacheKey);
    }

    public function test_heavy_endpoints_have_their_own_tighter_bucket(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $cacheKey = $this->exhaust('api-heavy', 'user:'.$owner->id, 20);

        $this->actingAs($owner, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/analytics?from=2026-09-01&to=2026-09-01")
            ->assertTooManyRequests();
        $this->actingAs($owner, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/day-close/preview")
            ->assertTooManyRequests();

        // The general bucket is untouched by the heavy one.
        $this->actingAs($owner, 'web')->getJson('/api/v1/organization')->assertOk();

        RateLimiter::clear($cacheKey);
    }

    public function test_public_routes_do_not_consume_the_authenticated_bucket(): void
    {
        [, , $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $cacheKey = $this->exhaust('api', 'user:127.0.0.1', 300);

        $this->getJson("/api/v1/public/tables/{$table->public_token}")->assertOk();

        RateLimiter::clear($cacheKey);
    }

    public function test_unauthenticated_requests_still_get_401_not_429(): void
    {
        $this->getJson('/api/v1/organization')->assertUnauthorized();
    }
}
