<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Trusted Proxies
    |--------------------------------------------------------------------------
    |
    | Read by Illuminate\Http\Middleware\TrustProxies. Comma-separated IPs or
    | CIDR ranges of the load balancer / reverse proxy that terminates TLS in
    | front of this API (e.g. "10.0.0.0/8,172.16.0.0/12").
    |
    | Only when the immediate peer matches one of these is X-Forwarded-For /
    | -Proto / -Host / -Port honoured. That is what makes $request->ip() the
    | real client address behind a proxy — every IP-keyed rate limiter
    | (login, public-*, whatsapp-webhook) depends on it; without it they
    | collapse into one shared bucket per proxy. And it is what lets
    | $request->secure() see the original HTTPS scheme.
    |
    | Unset/empty (the default) trusts no proxy: forwarded headers are
    | ignored, so a direct client can never spoof its IP. "*" trusts any
    | peer — only acceptable when the API is reachable exclusively through
    | a proxy that overwrites (never appends to) X-Forwarded-For.
    |
    */

    'proxies' => env('TRUSTED_PROXIES') ?: null,

];
