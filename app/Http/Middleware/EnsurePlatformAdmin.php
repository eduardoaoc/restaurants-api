<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Coarse gate for the whole /platform namespace: rejects any request from
 * a User with no platform role, regardless of any tenant role they may
 * hold — an Organization owner is not, by that fact alone, a platform
 * admin. Fine-grained abilities (view vs manage, per resource) are then
 * checked per-route via the platform.* Gates in PlatformAuthServiceProvider.
 *
 * Deliberately NOT a Gate::before — this middleware only ever runs on
 * /platform/* routes (see routes/api.php), so it can never affect
 * authorization on a tenant endpoint.
 */
class EnsurePlatformAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->isPlatformAdmin()) {
            throw new HttpException(403, 'Platform administrator access required.');
        }

        return $next($request);
    }
}
