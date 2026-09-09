<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Blocks any authenticated request from a suspended User — defense in
 * depth alongside PlatformUserController::updateStatus() deleting the
 * user's `sessions` rows on suspension. Applied first, before `tenant` and
 * `platform_admin`, on both the tenant and platform route groups, so a
 * suspended user is rejected the same way regardless of which namespace
 * they hit.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->isSuspended()) {
            throw new HttpException(403, 'This account has been suspended.');
        }

        return $next($request);
    }
}
