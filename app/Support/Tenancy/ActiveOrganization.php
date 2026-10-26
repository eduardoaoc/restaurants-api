<?php

namespace App\Support\Tenancy;

use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Http\Request;

/**
 * Which organization a session operates on.
 *
 * The session may carry a preferred organization id (set by
 * PUT /auth/active-organization or right after accepting an invitation).
 * It is only a preference: it is honoured exactly when the user still holds
 * an ACTIVE membership there, otherwise the long-standing rule applies —
 * the lowest-id active membership. The client can never name an
 * organization it does not belong to.
 */
class ActiveOrganization
{
    public const SESSION_KEY = 'active_organization_id';

    public static function preferredId(Request $request): ?int
    {
        if (! $request->hasSession()) {
            return null;
        }

        $value = $request->session()->get(self::SESSION_KEY);

        return is_numeric($value) ? (int) $value : null;
    }

    public static function resolve(User $user, ?int $preferredId): ?Organization
    {
        if ($preferredId !== null) {
            $preferred = self::activeMemberships($user)->whereKey($preferredId)->first();

            if ($preferred !== null) {
                return $preferred;
            }
        }

        return self::activeMemberships($user)
            // Explicit order: without it Postgres may return any row, so a
            // multi-organization user could land in a different tenant from
            // one request to the next.
            ->orderBy('organizations.id')
            ->first();
    }

    public static function remember(Request $request, int $organizationId): void
    {
        if ($request->hasSession()) {
            $request->session()->put(self::SESSION_KEY, $organizationId);
        }
    }

    /**
     * @return BelongsToMany<Organization, User>
     */
    private static function activeMemberships(User $user)
    {
        return $user->organizations()->wherePivot('status', OrganizationUser::STATUS_ACTIVE);
    }
}
