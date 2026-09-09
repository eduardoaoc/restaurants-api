<?php

namespace App\Http\Middleware;

use App\Models\Organization;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Resolves the active Organization (tenant) for the authenticated user
 * and stores it in the TenantContext for the rest of the request.
 *
 * This middleware assumes it runs after authentication. It never trusts
 * an organization id supplied by the client; the only source of truth is
 * the user's own organization membership.
 */
class ResolveTenant
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            throw new HttpException(401, 'Unauthenticated.');
        }

        $organization = $user->organizations()->first();

        if (! $organization) {
            throw new HttpException(403, 'The authenticated user has no organization.');
        }

        // Bloco 0: a platform admin suspends an Organization through
        // /platform/organizations/{organization}/status. Enforcing that
        // here — before any tenant controller runs — is what actually
        // gives suspension teeth: it also blocks the organization's own
        // owner from calling PATCH /organization to flip status back to
        // active themselves, since they can never reach that controller
        // while suspended. No tenant endpoint is reachable while
        // suspended, without exception.
        if ($organization->status === Organization::STATUS_SUSPENDED) {
            throw new HttpException(403, 'This organization has been suspended.');
        }

        $this->tenantContext->setOrganizationId($organization->id);

        return $next($request);
    }
}
