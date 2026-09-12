<?php

namespace App\Support\Auth;

use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\PlatformRoleAssignment;
use App\Models\Restaurant;
use App\Models\User;
use App\Models\UserRole;
use App\Support\Restaurants\RestaurantScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Builds the full authorization context for an authenticated user: every
 * organization they belong to, the restaurants they can reach within each,
 * and the capabilities (permission slugs) that actually apply at each of
 * those two scopes — plus their platform-level access, kept entirely
 * separate from tenant access (see User::hasPlatformPermission()).
 *
 * This is a READ PROJECTION over the existing role/permission
 * relationships (user_roles -> roles -> permissions, and their platform_*
 * counterparts). It introduces no new authorization and no role-name
 * shortcuts: every capability listed here comes straight from the
 * Permission/PlatformPermission rows a role actually grants. The real
 * Policies (StaffPolicy, RestaurantPolicy, PlatformAuthServiceProvider,
 * ...) remain the only place authorization is enforced — this class only
 * describes, ahead of time, what those Policies would currently allow, so
 * the frontend can adapt navigation/UX without probing endpoints for 403s.
 *
 * Scoping rule (see RestaurantScope's own docblock for the domain
 * justification): a role assignment with restaurant_id null is
 * organization-wide and contributes to EVERY restaurant of that
 * organization; a role assignment scoped to one restaurant_id contributes
 * only to that restaurant, and ONLY to that restaurant.
 *
 *   - Organization-level roles/permissions come exclusively from
 *     organization-wide (restaurant_id null) role assignments — this is
 *     how the owner is set up (see InteractsWithTenants::assignRole()) and
 *     is the only case where a capability genuinely applies "to the whole
 *     organization" rather than to one specific restaurant.
 *   - Restaurant-level permissions are the union of those same org-wide
 *     grants (they apply everywhere) plus any grant scoped specifically to
 *     that one restaurant. A role held at Restaurant B (e.g. kitchen)
 *     therefore never leaks into what is shown for Restaurant A (e.g.
 *     waiter) for a staff member linked to both, and a restaurant-scoped
 *     role (the only kind CreateStaffAction ever creates — manager
 *     included) never inflates the organization-level list either, even
 *     though User::hasPermission() itself is restaurant_id-agnostic and
 *     would return true for it at the organization scope too. This
 *     endpoint is intentionally stricter/more precise than that raw check
 *     for display purposes; it never under-reports a capability the real
 *     Policies would grant for a reachable restaurant (see restaurant-
 *     level permissions above), only the organization-wide bucket is
 *     narrowed to avoid implying a restaurant-scoped grant is universal.
 */
class AuthContextBuilder
{
    /**
     * @return array{
     *     user: array{id: int, name: string, email: string, status: string},
     *     platform: array{is_platform_admin: bool, roles: array<int, string>, permissions: array<int, string>},
     *     organizations: array<int, array<string, mixed>>,
     * }
     */
    public function build(User $user): array
    {
        $user->loadMissing([
            'organizations',
            'userRoles.role.permissions',
            'platformRoleAssignments.platformRole.permissions',
        ]);

        return [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'status' => $user->status,
            ],
            'platform' => $this->buildPlatform($user),
            // Passo 2.8B-FIX: an organization where this user's OWN
            // membership is inactive (OrganizationUser::STATUS_INACTIVE —
            // an owner/manager deactivated them there) is left out
            // entirely — the frontend must never treat it as a usable
            // operational context, even though the user's account and
            // membership row still exist. This is unrelated to
            // Organization::status itself (a SUSPENDED organization still
            // appears, transparently, for members with an active
            // membership — see AuthContextTest).
            'organizations' => $user->organizations
                ->filter(fn (Organization $organization) => $organization->pivot->status === OrganizationUser::STATUS_ACTIVE)
                ->map(fn (Organization $organization) => $this->buildOrganization($user, $organization))
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array{is_platform_admin: bool, roles: array<int, string>, permissions: array<int, string>}
     */
    private function buildPlatform(User $user): array
    {
        $platformRoles = $user->platformRoleAssignments
            ->map(fn (PlatformRoleAssignment $assignment) => $assignment->platformRole)
            ->filter()
            ->unique('id')
            ->values();

        $permissions = $platformRoles
            ->flatMap(fn ($role) => $role->permissions->pluck('slug'))
            ->unique()
            ->sort()
            ->values()
            ->all();

        return [
            'is_platform_admin' => $platformRoles->isNotEmpty(),
            'roles' => $platformRoles->pluck('slug')->sort()->values()->all(),
            'permissions' => $permissions,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildOrganization(User $user, Organization $organization): array
    {
        /** @var Collection<int, UserRole> $organizationUserRoles */
        $organizationUserRoles = $user->userRoles->where('organization_id', $organization->id);

        // Organization-level roles/permissions come ONLY from organization-
        // wide role assignments (restaurant_id null) — the ones that, per
        // RestaurantScope's own domain rule, genuinely apply across every
        // restaurant of the organization. A role assignment scoped to one
        // restaurant_id is deliberately excluded here even though it
        // happens to grant the same permission slug (e.g. a manage_users
        // permission held only at Restaurant A) — it is reported under
        // that restaurant below, not promoted to the organization scope,
        // so the response never implies a restaurant-scoped grant applies
        // organization-wide (see class docblock and the Bloco 1.2A report,
        // section 9).
        $organizationWideUserRoles = $organizationUserRoles->filter(
            fn (UserRole $userRole) => $userRole->restaurant_id === null
        );

        $accessibleRestaurantIds = RestaurantScope::accessibleRestaurantIds($user, $organization);

        $restaurants = $this->restaurantsQuery($organization, $accessibleRestaurantIds)
            ->get()
            ->map(fn (Restaurant $restaurant) => $this->buildRestaurant($restaurant, $organizationUserRoles))
            ->values()
            ->all();

        return [
            'id' => $organization->id,
            'name' => $organization->name,
            'slug' => $organization->slug,
            'status' => $organization->status,
            'roles' => $this->roleSlugsFor($organizationWideUserRoles),
            'permissions' => $this->permissionSlugsFor($organizationWideUserRoles),
            'restaurants' => $restaurants,
        ];
    }

    /**
     * @param  Collection<int, UserRole>  $organizationUserRoles
     * @return array<string, mixed>
     */
    private function buildRestaurant(Restaurant $restaurant, Collection $organizationUserRoles): array
    {
        $applicable = $organizationUserRoles->filter(
            fn (UserRole $userRole) => $userRole->restaurant_id === null || $userRole->restaurant_id === $restaurant->id
        );

        return [
            'id' => $restaurant->id,
            'name' => $restaurant->name,
            'slug' => $restaurant->slug,
            'status' => $restaurant->status,
            'roles' => $this->roleSlugsFor($applicable),
            'permissions' => $this->permissionSlugsFor($applicable),
        ];
    }

    /**
     * @param  Collection<int, UserRole>  $userRoles
     * @return array<int, string>
     */
    private function roleSlugsFor(Collection $userRoles): array
    {
        return $userRoles
            ->map(fn (UserRole $userRole) => $userRole->role?->slug)
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, UserRole>  $userRoles
     * @return array<int, string>
     */
    private function permissionSlugsFor(Collection $userRoles): array
    {
        return $userRoles
            ->flatMap(fn (UserRole $userRole) => $userRole->role?->permissions->pluck('slug') ?? [])
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @param  array<int, int>|null  $accessibleRestaurantIds  null means "every restaurant in the organization"
     * @return Builder<Restaurant>
     */
    private function restaurantsQuery(Organization $organization, ?array $accessibleRestaurantIds): Builder
    {
        $query = Restaurant::query()
            ->where('organization_id', $organization->id)
            ->orderBy('name');

        if ($accessibleRestaurantIds !== null) {
            $query->whereIn('id', $accessibleRestaurantIds);
        }

        return $query;
    }
}
