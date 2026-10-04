<?php

namespace Tests\Feature\Security;

use App\Models\Category;
use App\Models\ModifierGroup;
use App\Models\Order;
use App\Models\Permission;
use App\Models\Restaurant;
use App\Models\RestaurantProduct;
use App\Models\Role;
use App\Models\Table;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithOrders;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class AuthorizationScopeTest extends TestCase
{
    use InteractsWithOrders, InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    private function mixedRoles(): array
    {
        [$organization, $owner, $a1] = $this->createTenant();
        $a2 = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $user = $this->createStaff($organization, $a1, 'manager', 'M-SCOPE');
        $a2->users()->attach($user->id, ['sub_id' => 'W-SCOPE']);
        $this->assignRole($user, 'waiter', $organization, $a2);

        return [$organization, $owner, $a1, $a2, $user];
    }

    public function test_helpers_distinguish_organization_restaurant_and_foreign_grants(): void
    {
        [$organization, $owner, $a1, $a2, $user] = $this->mixedRoles();
        [$foreign, , $b1] = $this->createTenant();
        $this->assertFalse($user->hasOrganizationPermission('manage_restaurants', $organization));
        $this->assertTrue($user->hasRestaurantPermission('manage_restaurants', $a1));
        $this->assertFalse($user->hasRestaurantPermission('manage_restaurants', $a2));
        $this->assertTrue($user->hasRestaurantPermission('close_daily_operation', $a2));
        $this->assertFalse($user->hasRestaurantPermission('manage_restaurants', $b1));
        $this->assertFalse($user->hasOrganizationPermission('manage_restaurants', $foreign));
        foreach ([$a1, $a2] as $restaurant) {
            $this->assertTrue($owner->hasRestaurantPermission('manage_restaurants', $restaurant));
        }
        $this->assertTrue($owner->hasOrganizationPermission('manage_restaurants', $organization));
        $this->assertFalse(method_exists(User::class, 'hasPermission'));
    }

    #[DataProvider('parityActors')]
    public function test_auth_context_matches_settings_history_close_and_activity_enforcement(string $actorKind): void
    {
        [, $owner, $a1, $a2, $user] = $this->mixedRoles();
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'scope-test-key',
            'broadcasting.connections.reverb.secret' => 'scope-test-secret',
            'broadcasting.connections.reverb.app_id' => 'scope-test-app',
        ]);
        require base_path('routes/channels.php');
        $actor = $actorKind === 'owner' ? $owner : $user;

        $context = $this->actingAs($actor, 'web')->getJson('/api/v1/auth/context')->assertOk();
        $restaurants = collect($context->json('data.organizations.0.restaurants'))->keyBy('id');
        foreach ([$a1, $a2] as $restaurant) {
            $permissions = $restaurants[$restaurant->id]['permissions'];
            $canManage = in_array('manage_restaurants', $permissions, true);
            $canView = in_array('view_daily_closes', $permissions, true);
            $canActivity = in_array('view_activity', $permissions, true);
            $this->assertSame($canManage, Gate::forUser($actor)->allows('manageSettings', $restaurant));
            $this->assertSame($canView, Gate::forUser($actor)->allows('viewDayCloses', $restaurant));
            $this->assertSame(in_array('close_daily_operation', $permissions, true), Gate::forUser($actor)->allows('closeDay', $restaurant));
            $this->actingAs($actor, 'web')->patchJson("/api/v1/restaurants/{$restaurant->id}/settings", ['customer_ordering_enabled' => true])->assertStatus($canManage ? 200 : 403);
            $this->actingAs($actor, 'web')->getJson("/api/v1/restaurants/{$restaurant->id}/day-closes")->assertStatus($canView ? 200 : 403);
            $this->actingAs($actor, 'web')->getJson("/api/v1/restaurants/{$restaurant->id}/day-close/preview")->assertOk();
            $this->actingAs($actor, 'web')->postJson('/broadcasting/auth', ['channel_name' => "private-restaurant.{$restaurant->id}.activity", 'socket_id' => '1234.5678'])->assertStatus($canActivity ? 200 : 403);
            $this->actingAs($actor, 'web')->postJson('/broadcasting/auth', ['channel_name' => "private-restaurant.{$restaurant->id}", 'socket_id' => '1234.5678'])->assertOk();
        }

    }

    public static function parityActors(): array
    {
        return [['mixed'], ['owner']];
    }

    public function test_separate_manager_assignments_allow_each_target_without_becoming_organization_wide(): void
    {
        [$organization, , $a1, $a2, $user] = $this->mixedRoles();
        $user->userRoles()->where('restaurant_id', $a2->id)->delete();
        $this->assignRole($user, 'manager', $organization, $a2);
        foreach ([$a1, $a2] as $restaurant) {
            $this->actingAs($user, 'web')->getJson("/api/v1/restaurants/{$restaurant->id}/settings")->assertOk();
            $this->getJson("/api/v1/restaurants/{$restaurant->id}/day-closes")->assertOk();
            $this->assertTrue(Gate::forUser($user)->allows('viewActivity', $restaurant));
            $this->assertTrue(Gate::forUser($user)->allows('closeDay', $restaurant));
        }
        $this->assertFalse($user->hasOrganizationPermission('manage_restaurants', $organization));
    }

    public function test_scoped_manager_cannot_create_restaurants_or_manage_global_product_media(): void
    {
        [$organization, $owner, $a1, , $user] = $this->mixedRoles();
        $product = $this->createProduct($organization);
        $this->actingAs($user, 'web')->postJson('/api/v1/restaurants', ['name' => 'Unauthorized', 'slug' => 'unauthorized'])->assertForbidden();
        $this->getJson('/api/v1/products')->assertForbidden();
        $this->patchJson("/api/v1/products/{$product->id}", ['internal_name' => 'Unauthorized'])->assertForbidden();
        $this->deleteJson("/api/v1/products/{$product->id}/media/image")->assertForbidden();
        $this->assertTrue(Gate::forUser($owner)->allows('create', [Restaurant::class, $organization]));
        $this->assertTrue($user->hasRestaurantPermission('manage_products', $a1));
    }

    public function test_catalog_and_structure_writes_check_the_actual_restaurant(): void
    {
        [$organization, , $a1, $a2, $user] = $this->mixedRoles();
        foreach ([$a1, $a2] as $restaurant) {
            $canManage = $restaurant->is($a1);
            $this->assertSame($canManage, Gate::forUser($user)->allows('create', [Category::class, $restaurant]));
            $this->assertSame($canManage, Gate::forUser($user)->allows('create', [RestaurantProduct::class, $restaurant]));
            $product = $this->createRestaurantProduct($restaurant, $this->createProduct($organization));
            $this->assertSame($canManage, Gate::forUser($user)->allows('create', [ModifierGroup::class, $product]));
            $restaurant->settings()->update(['waiter_table_management_enabled' => false]);
            $this->assertSame($canManage, Gate::forUser($user)->allows('manageStructure', [Table::class, $restaurant]));
            $this->actingAs($user, 'web')->postJson("/api/v1/restaurant-products/{$product->id}/modifier-groups", [
                'internal_name' => 'Extras', 'min_select' => 0, 'max_select' => 1, 'required' => false,
                'translations' => [['locale' => 'en', 'name' => 'Extras']],
            ])->assertStatus($canManage ? 201 : 403);
        }
    }

    public function test_revocation_is_immediate_even_with_loaded_relations(): void
    {
        [, , $a1, $a2, $user] = $this->mixedRoles();
        $user->load('userRoles.role.permissions', 'roles.permissions', 'restaurants');
        $this->assertTrue($user->hasRestaurantPermission('manage_restaurants', $a1));
        $user->userRoles()->where('restaurant_id', $a1->id)->delete();
        $this->assertFalse($user->hasRestaurantPermission('manage_restaurants', $a1));
        $this->actingAs($user, 'web')->getJson("/api/v1/restaurants/{$a1->id}/settings")->assertForbidden();
        $this->assertTrue($user->hasRestaurantPermission('close_daily_operation', $a2));
    }

    public function test_foreign_org_remains_hidden_even_when_both_restaurants_are_memberships(): void
    {
        [, , , , $user] = $this->mixedRoles();
        [$other, , $b1] = $this->createTenant();
        $other->users()->attach($user->id);
        $b1->users()->attach($user->id, ['sub_id' => 'B-SCOPE']);
        $this->assertFalse($user->hasRestaurantPermission('manage_restaurants', $b1));
        $this->actingAs($user, 'web')->getJson("/api/v1/restaurants/{$b1->id}/settings")->assertNotFound();
    }

    public function test_permission_revocation_ignores_preloaded_role_permissions(): void
    {
        [$organization, $owner, $a1, , $user] = $this->mixedRoles();
        $user->load('userRoles.role.permissions');
        $owner->load('userRoles.role.permissions');
        $manager = Role::where('slug', 'manager')->firstOrFail();
        $permission = Permission::where('slug', 'manage_restaurants')->firstOrFail();
        $manager->permissions()->detach($permission->id);
        $this->assertFalse($user->hasRestaurantPermission('manage_restaurants', $a1));
        $this->assertTrue($owner->hasOrganizationPermission('manage_restaurants', $organization));
        $this->actingAs($user, 'web')->getJson("/api/v1/restaurants/{$a1->id}/settings")->assertForbidden();
    }

    public function test_operational_and_financial_abilities_do_not_borrow_manager_grants(): void
    {
        [$organization, $owner, $a1, $a2, $user] = $this->mixedRoles();
        $user->userRoles()->where('restaurant_id', $a2->id)->delete();
        $this->assignRole($user, 'kitchen', $organization, $a2);
        foreach ([$a1, $a2] as $restaurant) {
            $allowed = $restaurant->is($a1);
            $table = $this->createTable($restaurant);
            $session = $this->openSession($table, $owner);
            foreach (['assignWaiter', 'transfer', 'recordPayment'] as $ability) {
                $this->assertSame($allowed, Gate::forUser($user)->allows($ability, $session));
            }
            $this->assertSame($allowed, Gate::forUser($user)->allows('create', [Order::class, $table]));
        }
    }

    public function test_staff_assignment_reconciliation_preserves_memberships_in_other_organizations(): void
    {
        [$organization, $owner, $a1] = $this->createTenant();
        [$foreign, , $b1] = $this->createTenant();
        $staff = $this->createStaff($organization, $a1, 'waiter', 'SHARED-A');
        $b1->users()->attach($staff->id, ['sub_id' => 'SHARED-B']);
        $this->assignRole($staff, 'waiter', $foreign, $b1);
        $this->actingAs($owner, 'web')->patchJson("/api/v1/staff/{$staff->id}", [
            'restaurant_assignments' => [['restaurant_id' => $a1->id, 'sub_id' => 'SHARED-A-NEW']],
        ])->assertOk();
        $this->assertTrue($staff->restaurants()->whereKey($b1->id)->exists());
        $this->assertTrue($staff->userRoles()->where('organization_id', $foreign->id)->where('restaurant_id', $b1->id)->exists());
    }

    public function test_staff_create_checks_all_proposed_restaurants_and_update_checks_all_affected_restaurants(): void
    {
        [$organization, , $a1, $a2, $user] = $this->mixedRoles();
        $this->actingAs($user, 'web')->postJson('/api/v1/staff', [
            'name' => 'Forbidden Multi', 'email' => 'forbidden-multi@example.test', 'password' => 'password123', 'role' => 'manager',
            'restaurant_assignments' => [['restaurant_id' => $a1->id, 'sub_id' => 'N-1'], ['restaurant_id' => $a2->id, 'sub_id' => 'N-2']],
        ])->assertForbidden();
        $this->assertDatabaseMissing('users', ['email' => 'forbidden-multi@example.test']);
        $staff = $this->createStaff($organization, $a1, 'waiter', 'S-1');
        $a2->users()->attach($staff->id, ['sub_id' => 'S-2']);
        $this->assignRole($staff, 'waiter', $organization, $a2);
        $this->patchJson("/api/v1/staff/{$staff->id}", ['role' => 'manager'])->assertForbidden();
        $this->assertSame(Role::where('slug', 'waiter')->firstOrFail()->id, $staff->userRoles()->where('restaurant_id', $a2->id)->firstOrFail()->role_id);
        $this->getJson("/api/v1/staff/{$staff->id}")->assertOk()->assertJsonCount(1, 'data.staff.restaurants')->assertJsonPath('data.staff.restaurants.0.id', $a1->id);
    }

    public function test_staff_new_assignment_cannot_expand_grants_into_waiter_restaurant(): void
    {
        [$organization, , $a1, $a2, $user] = $this->mixedRoles();
        $staff = $this->createStaff($organization, $a1, 'waiter', 'S-1');
        $this->actingAs($user, 'web')->patchJson("/api/v1/staff/{$staff->id}", [
            'restaurant_assignments' => [['restaurant_id' => $a1->id, 'sub_id' => 'S-1'], ['restaurant_id' => $a2->id, 'sub_id' => 'S-2']],
        ])->assertForbidden();
        $this->assertFalse($staff->restaurants()->whereKey($a2->id)->exists());
    }
}
