<?php

namespace App\Actions\Staff;

use App\Models\Organization;
use App\Models\RestaurantUser;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Support\Facades\DB;

/**
 * Updates an operational staff member, keeping organization_users,
 * restaurant_users, and user_roles consistent within one transaction.
 */
class UpdateStaffAction
{
    /**
     * @param  array{name?: string, email?: string, restaurant_id?: int, role?: string, sub_id?: string}  $data
     */
    public function execute(Organization $organization, User $staff, array $data): User
    {
        return DB::transaction(function () use ($organization, $staff, $data) {
            $staff->fill(array_intersect_key($data, array_flip(['name', 'email'])));
            $staff->save();

            $restaurantUser = RestaurantUser::query()->where('user_id', $staff->id)->first();
            $userRole = UserRole::query()
                ->where('user_id', $staff->id)
                ->where('organization_id', $organization->id)
                ->first();

            $restaurantId = $data['restaurant_id'] ?? $restaurantUser?->restaurant_id;

            if ($restaurantUser) {
                $restaurantUser->update([
                    'restaurant_id' => $restaurantId,
                    'sub_id' => $data['sub_id'] ?? $restaurantUser->sub_id,
                ]);
            }

            if ($userRole) {
                $roleUpdates = [];

                if (array_key_exists('role', $data)) {
                    $roleUpdates['role_id'] = Role::query()->where('slug', $data['role'])->firstOrFail()->id;
                }

                if ($restaurantId !== $userRole->restaurant_id) {
                    $roleUpdates['restaurant_id'] = $restaurantId;
                }

                if ($roleUpdates !== []) {
                    $userRole->update($roleUpdates);
                }
            }

            return $staff;
        });
    }
}
