<?php

namespace App\Actions\Staff;

use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Support\Facades\DB;

/**
 * Creates an operational staff member and wires up every tenant link
 * (organization_users, restaurant_users, user_roles) atomically.
 */
class CreateStaffAction
{
    /**
     * @param  array{name: string, email: string, password: string, restaurant_id: int, role: string, sub_id: string}  $data
     */
    public function execute(Organization $organization, array $data): User
    {
        return DB::transaction(function () use ($organization, $data) {
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

            return $user;
        });
    }
}
