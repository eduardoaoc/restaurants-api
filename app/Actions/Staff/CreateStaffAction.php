<?php

namespace App\Actions\Staff;

use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use App\Support\Audit\AuditLogger;
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
 * from this duplication; hasPermission() (User::hasPermission()) is
 * restaurant_id-agnostic, so the duplication costs nothing at permission-
 * check time and keeps every user_roles row self-describing.
 */
class CreateStaffAction
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * @param  array{name: string, email: string, password: string, role: string, restaurant_assignments: array<int, array{restaurant_id: int, sub_id: string}>}  $data
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

            $organization->users()->attach($user->id);

            $role = Role::query()->where('slug', $data['role'])->firstOrFail();

            $restaurantIds = [];

            foreach ($data['restaurant_assignments'] as $assignment) {
                // Scoped lookup: the restaurant must belong to this
                // organization, even if a caller bypasses request-level
                // validation.
                $restaurant = $organization->restaurants()->findOrFail($assignment['restaurant_id']);

                $restaurant->users()->attach($user->id, ['sub_id' => $assignment['sub_id']]);

                UserRole::query()->create([
                    'user_id' => $user->id,
                    'role_id' => $role->id,
                    'organization_id' => $organization->id,
                    'restaurant_id' => $restaurant->id,
                ]);

                $restaurantIds[] = $restaurant->id;
            }

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
}
