<?php

namespace App\Actions\Staff;

use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\RestaurantUser;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use App\Support\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Updates an operational staff member, keeping organization_users,
 * restaurant_users, and user_roles consistent within one transaction.
 */
class UpdateStaffAction
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * @param  array{name?: string, email?: string, restaurant_id?: int, role?: string, sub_id?: string}  $data
     *
     * $actor is optional (see CreateStaffAction). Audit `changes` is an
     * explicit whitelist — name, restaurant_id, role, sub_id — never
     * email (PII minimization, even though the field can be updated) and
     * never a raw dirty-attributes dump. No audit event at all is
     * recorded when nothing in the whitelist actually changed.
     */
    public function execute(Organization $organization, User $staff, array $data, ?User $actor = null): User
    {
        return DB::transaction(function () use ($organization, $staff, $data, $actor) {
            $originalName = $staff->name;

            $staff->fill(array_intersect_key($data, array_flip(['name', 'email'])));
            $staff->save();

            $restaurantUser = RestaurantUser::query()->where('user_id', $staff->id)->first();
            $userRole = UserRole::query()
                ->where('user_id', $staff->id)
                ->where('organization_id', $organization->id)
                ->first();

            $originalRestaurantId = $restaurantUser?->restaurant_id;
            $originalSubId = $restaurantUser?->sub_id;
            $originalRoleId = $userRole?->role_id;

            $restaurantId = $data['restaurant_id'] ?? $restaurantUser?->restaurant_id;

            if ($restaurantUser) {
                $restaurantUser->update([
                    'restaurant_id' => $restaurantId,
                    'sub_id' => $data['sub_id'] ?? $restaurantUser->sub_id,
                ]);
            }

            $newRole = null;

            if ($userRole) {
                $roleUpdates = [];

                if (array_key_exists('role', $data)) {
                    $newRole = Role::query()->where('slug', $data['role'])->firstOrFail();
                    $roleUpdates['role_id'] = $newRole->id;
                }

                if ($restaurantId !== $userRole->restaurant_id) {
                    $roleUpdates['restaurant_id'] = $restaurantId;
                }

                if ($roleUpdates !== []) {
                    $userRole->update($roleUpdates);
                }
            }

            if ($actor) {
                $changes = [];

                if ($staff->wasChanged('name')) {
                    $changes['name'] = ['old' => $originalName, 'new' => $staff->name];
                }

                if ($restaurantUser && $restaurantUser->wasChanged('restaurant_id')) {
                    $changes['restaurant_id'] = ['old' => $originalRestaurantId, 'new' => $restaurantUser->restaurant_id];
                }

                if ($restaurantUser && $restaurantUser->wasChanged('sub_id')) {
                    $changes['sub_id'] = ['old' => $originalSubId, 'new' => $restaurantUser->sub_id];
                }

                if ($newRole && $originalRoleId !== $newRole->id) {
                    $oldRoleSlug = $originalRoleId ? Role::query()->whereKey($originalRoleId)->value('slug') : null;
                    $changes['role'] = ['old' => $oldRoleSlug, 'new' => $newRole->slug];
                }

                if ($changes !== []) {
                    $this->auditLogger->log(
                        organizationId: $organization->id,
                        restaurantId: $restaurantId,
                        actorType: AuditLog::ACTOR_USER,
                        actor: $actor,
                        event: AuditLog::EVENT_STAFF_UPDATED,
                        resourceType: AuditLog::RESOURCE_STAFF,
                        resourceId: $staff->id,
                        changes: $changes,
                    );
                }
            }

            return $staff;
        });
    }
}
