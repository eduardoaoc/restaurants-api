<?php

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Concerns\InteractsWithTenants;
use Tests\Concerns\InteractsWithWhatsApp;
use Tests\TestCase;

class WebhookAndThrottleTest extends TestCase
{
    use InteractsWithTenants, InteractsWithWhatsApp, RefreshDatabase;

    public function test_invalid_modified_and_moderately_large_webhook_bodies_are_safe(): void
    {
        $this->configureWhatsApp();
        Http::preventStrayRequests();
        $this->postSignedWebhook('{}', null)->assertForbidden();
        $this->postSignedWebhook('{}', signatureOverride: 'sha256=invalid')->assertForbidden();
        $signature = 'sha256='.hash_hmac('sha256', '{}', self::WA_APP_SECRET);
        $this->postSignedWebhook('{"changed":true}', signatureOverride: $signature)->assertForbidden();
        $this->postSignedWebhook('{invalid JSON')->assertOk()->assertExactJson(['received' => true]);
        $this->postSignedWebhook(json_encode(['object' => 'unknown', 'padding' => str_repeat('x', 100000)]))->assertOk();
        $this->postSignedWebhook($this->statusWebhook([['id' => 'unknown', 'status' => 'read']]))->assertOk();
        $this->assertDatabaseCount('restaurant_day_close_deliveries', 0);
        Http::assertNothingSent();
    }

    public function test_public_and_webhook_throttle_buckets_ignore_forwarded_headers(): void
    {
        // Prefill counters instead of issuing hundreds of requests. The exact
        // production limits and named middleware still execute on each HTTP call.
        foreach ([
            ['public-menu', '127.0.0.1', 60, 'GET', '/public/tables/token'],
            ['public-orders', '127.0.0.1|token', 10, 'POST', '/public/tables/token/orders'],
            ['public-table-requests', '127.0.0.1|token', 10, 'POST', '/public/tables/token/requests/call-waiter'],
            ['public-table-requests', '127.0.0.1|token', 10, 'POST', '/public/tables/token/requests/bill'],
            ['public-feedback', '127.0.0.1|token', 10, 'POST', '/public/feedback/token'],
            ['whatsapp-webhook', '127.0.0.1', 600, 'POST', '/webhooks/whatsapp'],
        ] as [$limiter, $key, $maximum, $method, $url]) {
            $cacheKey = md5($limiter.$key);
            RateLimiter::clear($cacheKey);
            for ($i = 0; $i < $maximum; $i++) {
                RateLimiter::hit($cacheKey, 60);
            }
            $response = $this->json($method, '/api/v1'.$url, [], ['X-Forwarded-For' => '198.51.100.77', 'Forwarded' => 'for=198.51.100.88']);
            $response->assertTooManyRequests()->assertHeader('Retry-After');
            RateLimiter::clear($cacheKey);
        }
    }
}
