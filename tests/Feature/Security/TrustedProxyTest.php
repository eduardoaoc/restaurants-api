<?php

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class TrustedProxyTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private const CLIENT_IP = '198.51.100.77';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
    }

    private function exhaustPublicMenu(string $ip): string
    {
        $cacheKey = md5('public-menu'.$ip);
        RateLimiter::clear($cacheKey);

        for ($i = 0; $i < 60; $i++) {
            RateLimiter::hit($cacheKey, 60);
        }

        return $cacheKey;
    }

    public function test_forwarded_client_ip_is_used_when_the_peer_is_a_trusted_proxy(): void
    {
        config(['trustedproxy.proxies' => '127.0.0.1']);
        [, , $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);

        // The proxy's own address no longer owns the bucket...
        $proxyKey = $this->exhaustPublicMenu('127.0.0.1');
        $this->getJson("/api/v1/public/tables/{$table->public_token}", ['X-Forwarded-For' => self::CLIENT_IP])
            ->assertOk();
        RateLimiter::clear($proxyKey);

        // ...the real client behind it does.
        $clientKey = $this->exhaustPublicMenu(self::CLIENT_IP);
        $this->getJson("/api/v1/public/tables/{$table->public_token}", ['X-Forwarded-For' => self::CLIENT_IP])
            ->assertTooManyRequests();
        RateLimiter::clear($clientKey);
    }

    public function test_forwarded_headers_are_ignored_from_an_untrusted_peer(): void
    {
        config(['trustedproxy.proxies' => '10.0.0.0/8']);
        [, , $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);

        $peerKey = $this->exhaustPublicMenu('127.0.0.1');
        $this->getJson("/api/v1/public/tables/{$table->public_token}", ['X-Forwarded-For' => self::CLIENT_IP])
            ->assertTooManyRequests();
        RateLimiter::clear($peerKey);
    }

    public function test_no_proxy_is_trusted_by_default(): void
    {
        $this->assertNull(config('trustedproxy.proxies'));
    }
}
