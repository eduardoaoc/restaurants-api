<?php

namespace App\Actions\Staff;

use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\RestaurantUser;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use App\Support\Audit\AuditLogger;
use App\Support\Staff\EmployeeCodeGenerator;
use Illuminate\Support\Facades\DB;

/**
 * Creates an operational staff member and wires up every tenant link
 * (organization_users, restaurant_users, user_roles) atomically.
 *
 * Bloco 18: a staff member is linked to 1..N restaurants of the
 * organization, all under the same operational role (per-restaurant roles
 * are a possible future block, not this one — see report). One
 * restaurant_users row and one user_roles row are created per assignment,
 * matched 1:1 — user_roles.restaurant_id is not a vestigial "pick one"
 * marker, each row genuinely ties this role to that one restaurant.
 * RestaurantScope reads restaurant membership from restaurant_users, not
 * from this duplication. Permission enforcement reads grants scoped to
 * the target restaurant (or organization-wide grants), so each assignment
 * remains independently enforceable.
 */
class CreateStaffAction
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly EmployeeCodeGenerator $employeeCodes,
    ) {}

    /**
     * @param  array{name: string, email: string, password: string, role: string, restaurant_assignments: array<int, array{restaurant_id: int, sub_id?: string}>}  $data
     *
     * $actor is mandatory — there is no fallback to a "system" actor and
     * no silent audit skip. Every caller, including test fixtures, must
     * supply a real acting user; see InteractsWithTenants::createStaff()
     * for how test fixtures satisfy this without testing the Action
     * itself.
     */
    public function execute(Organization $organization, array $data, User $actor): User
    {
        return DB::transaction(function () use ($organization, $data, $actor) {
            $user = User::query()->create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
            ]);

            $restaurantIds = $this->attach($organization, $user, $data['role'], $data['restaurant_assignments']);

            $this->auditLogger->log(
                organizationId: $organization->id,
                // A single restaurant's audit trail (filtered by
                // restaurant_id) only makes unambiguous sense for a
                // single-restaurant assignment; a multi-restaurant hire is
                // recorded organization-wide (restaurant_id null) with the
                // full list in metadata instead of picking one arbitrarily.
                restaurantId: count($restaurantIds) === 1 ? $restaurantIds[0] : null,
                actorType: AuditLog::ACTOR_USER,
                actor: $actor,
                event: AuditLog::EVENT_STAFF_CREATED,
                resourceType: AuditLog::RESOURCE_STAFF,
                resourceId: $user->id,
                metadata: ['restaurant_ids' => $restaurantIds],
            );

            return $user;
        });
    }

    /**
     * Wires organization_users + one restaurant_users/user_roles pair per
     * assignment for an EXISTING user. Must run inside the caller's
     * transaction; no audit of its own. Reused by AcceptInvitationAction so
     * an invited staff member ends up linked exactly like one created here.
     *
     * @param  array<int, array{restaurant_id: int, sub_id?: string|null}>  $assignments  sub_id is optional: when absent (always, for new
     *                                                                                    API input) the backend generates it; a value is honoured only for
     *                                                                                    legacy pending invitations that still carry one and it is still free
     * @return array<int, int> restaurant ids, in assignment order
     */
    public function attach(Organization $organization, User $user, string $roleSlug, array $assignments): array
    {
        $organization->users()->attach($user->id);

        $role = Role::query()->where('slug', $roleSlug)->firstOrFail();

        $restaurantIds = [];

        foreach ($assignments as $assignment) {
            // Scoped lookup: the restaurant must belong to this
            // organization, even if a caller bypasses request-level
            // validation.
            $restaurant = $organization->restaurants()->findOrFail($assignment['restaurant_id']);

            $restaurant->users()->attach($user->id, [
                'sub_id' => $this->resolveCode($restaurant->id, $user->id, $assignment['sub_id'] ?? null),
            ]);

            UserRole::query()->create([
                'user_id' => $user->id,
                'role_id' => $role->id,
                'organization_id' => $organization->id,
                'restaurant_id' => $restaurant->id,
            ]);

            $restaurantIds[] = $restaurant->id;
        }

        return $restaurantIds;
    }

    /**
     * Keeps a legacy code that is still free at the restaurant; otherwise
     * generates one. Never overwrites an existing restaurant_users row.
     */
    private function resolveCode(int $restaurantId, int $userId, ?string $legacyCode): string
    {
        if ($legacyCode !== null && $legacyCode !== ''
            && ! RestaurantUser::query()->where('restaurant_id', $restaurantId)->where('sub_id', $legacyCode)->exists()) {
            return $legacyCode;
        }

        return $this->employeeCodes->generate($restaurantId, $userId);
    }
}
