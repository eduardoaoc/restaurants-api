<?php

namespace Tests\Feature\Tenancy;

use App\Models\Organization;
use App\Models\Permission;
use App\Models\Restaurant;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class RoleTest extends TestCase
{
    use RefreshDatabase;

    public function test_role_has_permissions(): void
    {
        $role = Role::query()->create(['name' => 'Waiter', 'slug' => 'waiter']);
        $permission = Permission::query()->create(['name' => 'Create orders', 'slug' => 'create_orders']);

        $role->permissions()->attach($permission);

        $this->assertTrue($role->fresh()->permissions->contains($permission));
        $this->assertTrue($permission->fresh()->roles->contains($role));
    }

    public function test_user_can_have_role_in_organization_context(): void
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->create();
        $role = Role::query()->create(['name' => 'Owner', 'slug' => 'owner']);

        $userRole = UserRole::query()->create([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'organization_id' => $organization->id,
            'restaurant_id' => null,
        ]);

        $this->assertTrue($user->fresh()->roles->contains($role));
        $this->assertNull($userRole->restaurant_id);
    }

    public function test_user_can_have_role_in_restaurant_context(): void
    {
        $organization = Organization::factory()->create();
        $restaurant = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $user = User::factory()->create();
        $role = Role::query()->create(['name' => 'Waiter', 'slug' => 'waiter']);

        $userRole = UserRole::query()->create([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'organization_id' => $organization->id,
            'restaurant_id' => $restaurant->id,
        ]);

        $this->assertSame($restaurant->id, $userRole->restaurant_id);
        $this->assertSame($organization->id, $userRole->organization_id);
    }

    public function test_user_role_rejects_a_restaurant_from_a_different_organization(): void
    {
        $organization = Organization::factory()->create();
        $otherOrganization = Organization::factory()->create();
        $restaurant = Restaurant::factory()->create(['organization_id' => $otherOrganization->id]);
        $user = User::factory()->create();
        $role = Role::query()->create(['name' => 'Waiter', 'slug' => 'waiter']);

        $this->expectException(InvalidArgumentException::class);

        UserRole::query()->create([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'organization_id' => $organization->id,
            'restaurant_id' => $restaurant->id,
        ]);
    }
}
