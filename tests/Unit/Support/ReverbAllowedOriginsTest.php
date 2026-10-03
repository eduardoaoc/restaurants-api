<?php

namespace Tests\Unit\Support;

use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Bloco 1.3B regression guard: Reverb's own verifyOrigin() (see
 * vendor/laravel/reverb/src/Protocols/Pusher/Server.php) extracts only the
 * HOST from the browser's Origin header (parse_url($origin, PHP_URL_HOST))
 * and matches it against config('reverb.apps.apps.0.allowed_origins') with
 * Str::is(). A scheme/port-qualified entry (the bug this block fixed) never
 * matches a real browser Origin and silently rejects every WebSocket
 * handshake — this test exercises the exact same extraction+match Reverb
 * performs, not just the raw config shape.
 */
class ReverbAllowedOriginsTest extends TestCase
{
    public function test_default_allowed_origins_are_bare_hostnames(): void
    {
        $allowedOrigins = config('reverb.apps.apps.0.allowed_origins');

        $this->assertNotEmpty($allowedOrigins);

        foreach ($allowedOrigins as $allowedOrigin) {
            $this->assertSame(
                parse_url($allowedOrigin, PHP_URL_HOST) ?? $allowedOrigin,
                $allowedOrigin,
                "allowed_origins entry [{$allowedOrigin}] must be a bare hostname (no scheme/port) — Reverb compares it against parse_url(\$origin, PHP_URL_HOST)."
            );
        }
    }

    public function test_default_allowed_origins_never_use_wildcard(): void
    {
        $this->assertNotContains('*', config('reverb.apps.apps.0.allowed_origins'));
    }

    /**
     * Reproduces Reverb's own verifyOrigin() logic against a realistic
     * dev-server Origin header, so this test fails the exact way the real
     * handshake failed before the fix.
     */
    public function test_real_browser_dev_origin_is_accepted_like_reverb_would(): void
    {
        $allowedOrigins = config('reverb.apps.apps.0.allowed_origins');

        foreach (['http://localhost:5173', 'http://localhost:5174', 'http://localhost:8080'] as $browserOrigin) {
            $host = parse_url($browserOrigin, PHP_URL_HOST);

            $matched = false;
            foreach ($allowedOrigins as $allowedOrigin) {
                if (Str::is($allowedOrigin, $host)) {
                    $matched = true;
                    break;
                }
            }

            $this->assertTrue($matched, "Reverb would reject Origin [{$browserOrigin}] with the current allowed_origins config.");
        }
    }
}
