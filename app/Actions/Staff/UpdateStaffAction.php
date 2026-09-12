<?php

namespace App\Actions\Staff;

use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\RestaurantUser;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use App\Support\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Updates an operational staff member, keeping organization_users,
 * restaurant_users, and user_roles consistent within one transaction.
 *
 * Bloco 18: `restaurant_assignments`, when present, REPLACES the staff
 * member's full restaurant set — assignments missing from the new list are
 * removed (both the restaurant_users row and its matching user_roles row),
 * assignments already present are updated in place (sub_id), and new ones
 * are created. A `role` change (with or without `restaurant_assignments`)
 * applies uniformly to every one of the staff member's restaurants — this
 * MVP does not support a different role per restaurant (see report).
 *
 * `status` (active/inactive — OrganizationUser::STATUSES) is the
 * deactivation mechanism, and it is deliberately NOT users.status: it
 * flips the pivot row in organization_users for THIS organization only,
 * so it never touches restaurant_users/user_roles (roles, restaurant
 * assignments, and every bit of operational history survive untouched)
 * and — critically — can never revert a platform-level suspension
 * (users.status, global, owned exclusively by PlatformUserController; see
 * Passo 2.8B-FIX). Enforcement of what an inactive-membership staff member
 * can no longer do within this organization is centralized in
 * ResolveTenant (tenant routes) and routes/channels.php (realtime); a
 * globally-suspended user is still blocked everywhere by EnsureUserIsActive
 * and AuthController::login regardless of this pivot's value.
 */
class UpdateStaffAction
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * @param  array{name?: string, email?: string, status?: string, role?: string, restaurant_assignments?: array<int, array{restaurant_id: int, sub_id: string}>}  $data
     *
     * $actor is mandatory — no fallback, no silent audit skip (see
     * CreateStaffAction). Audit `changes` is an explicit whitelist — name,
     * status, restaurant_ids, role — never email (PII minimization, even though
     * the field can be updated) and never a raw dirty-attributes dump. No
     * audit event at all is recorded when nothing in the whitelist
     * actually changed — a real actor does not mean a no-op update gets
     * logged.
     */
    public function execute(Organization $organization, User $staff, array $data, User $actor): User
    {
        return DB::transaction(function () use ($organization, $staff, $data, $actor) {
            $originalName = $staff->name;

            $staff->fill(array_intersect_key($data, array_flip(['name', 'email'])));
            $staff->save();

            // The membership row is guaranteed to exist for any staff
            // member reachable through this Action: CreateStaffAction
            // always attaches organization_users in the same transaction
            // it attaches restaurant_users, and this method never removes
            // that row — see staffQuery()'s docblock in StaffController.
            $membership = OrganizationUser::query()
                ->where('organization_id', $organization->id)
                ->where('user_id', $staff->id)
                ->firstOrFail();

            $originalMembershipStatus = $membership->status;

            if (array_key_exists('status', $data) && $data['status'] !== $membership->status) {
                $membership->update(['status' => $data['status']]);
            }

            $existingRestaurantUsers = RestaurantUser::query()->where('user_id', $staff->id)->get()->keyBy('restaurant_id');
            $existingUserRoles = UserRole::query()
                ->where('user_id', $staff->id)
                ->where('organization_id', $organization->id)
                ->get()
                ->keyBy('restaurant_id');

            $originalRestaurantIds = $existingRestaurantUsers->keys()->sort()->values()->all();
            $originalRoleId = $existingUserRoles->first()?->role_id;

            $newRole = array_key_exists('role', $data)
                ? Role::query()->where('slug', $data['role'])->firstOrFail()
                : null;
            $roleIdToApply = $newRole->id ?? $originalRoleId;

            $assignments = $data['restaurant_assignments'] ?? null;
            $newRestaurantIds = $originalRestaurantIds;

            if ($assignments !== null) {
                $desired = collect($assignments)->keyBy('restaurant_id');

                foreach ($existingRestaurantUsers as $restaurantId => $restaurantUser) {
                    if (! $desired->has($restaurantId)) {
                        $restaurantUser->delete();
                        $existingUserRoles->get($restaurantId)?->delete();
                    }
                }

                foreach ($desired as $restaurantId => $assignment) {
                    // Scoped lookup: the restaurant must belong to this
                    // organization, even if a caller bypasses request-level
                    // validation.
                    $restaurant = $organization->restaurants()->findOrFail($restaurantId);

                    $restaurantUser = $existingRestaurantUsers->get($restaurantId);

                    if ($restaurantUser) {
                        $restaurantUser->update(['sub_id' => $assignment['sub_id']]);
                    } else {
                        $restaurant->users()->attach($staff->id, ['sub_id' => $assignment['sub_id']]);
                    }

                    $userRole = $existingUserRoles->get($restaurantId);

                    if ($userRole) {
                        if ($userRole->role_id !== $roleIdToApply) {
                            $userRole->update(['role_id' => $roleIdToApply]);
                        }
                    } else {
                        UserRole::query()->create([
                            'user_id' => $staff->id,
                            'role_id' => $roleIdToApply,
                            'organization_id' => $organization->id,
                            'restaurant_id' => $restaurantId,
                        ]);
                    }
                }

                $newRestaurantIds = $desired->keys()->sort()->values()->all();
            } elseif ($newRole !== null) {
                // Role changed, restaurant set left untouched: apply to
                // every existing restaurant of this staff member.
                UserRole::query()
                    ->where('user_id', $staff->id)
                    ->where('organization_id', $organization->id)
                    ->update(['role_id' => $newRole->id]);
            }

            $changes = [];

            if ($staff->wasChanged('name')) {
                $changes['name'] = ['old' => $originalName, 'new' => $staff->name];
            }

            if ($originalMembershipStatus !== $membership->status) {
                $changes['status'] = ['old' => $originalMembershipStatus, 'new' => $membership->status];
            }

            if ($assignments !== null && $newRestaurantIds !== $originalRestaurantIds) {
                $changes['restaurant_ids'] = ['old' => $originalRestaurantIds, 'new' => $newRestaurantIds];
            }

            if ($newRole !== null && $originalRoleId !== $newRole->id) {
                $oldRoleSlug = $originalRoleId ? Role::query()->whereKey($originalRoleId)->value('slug') : null;
                $changes['role'] = ['old' => $oldRoleSlug, 'new' => $newRole->slug];
            }

            if ($changes !== []) {
                $this->auditLogger->log(
                    organizationId: $organization->id,
                    restaurantId: count($newRestaurantIds) === 1 ? $newRestaurantIds[0] : null,
                    actorType: AuditLog::ACTOR_USER,
                    actor: $actor,
                    event: AuditLog::EVENT_STAFF_UPDATED,
                    resourceType: AuditLog::RESOURCE_STAFF,
                    resourceId: $staff->id,
                    changes: $changes,
                );
            }

            return $staff;
        });
    }
}
