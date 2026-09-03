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
 */
class CreateStaffAction
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * @param  array{name: string, email: string, password: string, restaurant_id: int, role: string, sub_id: string}  $data
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

            // Scoped lookup: the restaurant must belong to this organization,
            // even if a caller bypasses request-level validation.
            $restaurant = $organization->restaurants()->findOrFail($data['restaurant_id']);

            $restaurant->users()->attach($user->id, ['sub_id' => $data['sub_id']]);

            $role = Role::query()->where('slug', $data['role'])->firstOrFail();

            UserRole::query()->create([
                'user_id' => $user->id,
                'role_id' => $role->id,
                'organization_id' => $organization->id,
                'restaurant_id' => $restaurant->id,
            ]);

            $this->auditLogger->log(
                organizationId: $organization->id,
                restaurantId: $restaurant->id,
                actorType: AuditLog::ACTOR_USER,
                actor: $actor,
                event: AuditLog::EVENT_STAFF_CREATED,
                resourceType: AuditLog::RESOURCE_STAFF,
                resourceId: $user->id,
                metadata: ['restaurant_id' => $restaurant->id],
            );

            return $user;
        });
    }
}
