<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline security headers on every response (global middleware, so
 * 401/429/error responses get them too).
 *
 * Deliberately no X-Frame-Options/CSP: this is a JSON API (nothing to
 * clickjack) and the Cierre Diario PDF may be embedded by the SPA, which a
 * blanket frame/CSP policy would break. HSTS is only sent over HTTPS — a
 * header on plain HTTP is ignored by browsers anyway, and behind a TLS
 * proxy $request->secure() relies on TRUSTED_PROXIES.
 */
class SetSecurityHeaders
{
    public const HSTS = 'max-age=31536000; includeSubDomains';

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        if ($request->secure()) {
            $response->headers->set('Strict-Transport-Security', self::HSTS);
        }

        // Emitted by PHP itself (expose_php), outside Symfony's header bag.
        $response->headers->remove('X-Powered-By');
        if (! headers_sent()) {
            header_remove('X-Powered-By');
        }

        return $response;
    }
}
