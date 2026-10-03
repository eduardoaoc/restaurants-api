<?php

namespace Tests\Feature\Staff;

use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\Restaurant;
use App\Models\RestaurantUser;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Passo 2.8B-FIX — tenant-scoped Staff deactivation (OrganizationUser::
 * STATUSES, active/inactive) vs. platform-level suspension (User::STATUSES,
 * active/suspended). These are two SEPARATE authorities:
 *
 *   - users.status: GLOBAL, platform-admin-only (PlatformUserController).
 *     The Staff API never reads or writes it.
 *   - organization_users.status: THIS organization's own authority. An
 *     owner/manager deactivates/reactivates a staff member's membership
 *     here via PATCH /api/v1/staff/{user} — it can never touch, and can
 *     never override, a platform suspension.
 *
 * See OrganizationUser, ResolveTenant, AuthContextBuilder, and
 * routes/channels.php for where each authority is enforced.
 */
class StaffStatusTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    private function authorizeChannel(int $restaurantId): TestResponse
    {
        return $this->postJson('/broadcasting/auth', [
            'channel_name' => "private-restaurant.{$restaurantId}",
            'socket_id' => '1234.5678',
        ]);
    }

    private function membership(Organization $organization, User $user): OrganizationUser
    {
        return OrganizationUser::query()
            ->where('organization_id', $organization->id)
            ->where('user_id', $user->id)
            ->firstOrFail();
    }

    // --- StaffResource / defaults ------------------------------------

    public function test_staff_resource_exposes_operational_status(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $staff = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');

        $this->actingAs($owner, 'web')
            ->getJson("/api/v1/staff/{$staff->id}")
            ->assertOk()
            ->assertJsonPath('data.staff.status', OrganizationUser::STATUS_ACTIVE);
    }

    public function test_new_staff_membership_is_active_by_default(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();

        $response = $this->actingAs($owner, 'web')->postJson('/api/v1/staff', [
            'name' => 'Carlos',
            'email' => 'carlos-'.uniqid().'@example.com',
            'password' => 'password123',
            'role' => 'waiter',
            'restaurant_assignments' => [
                ['restaurant_id' => $restaurant->id, 'sub_id' => 'W-1'],
            ],
        ])->assertCreated();

        $response->assertJsonPath('data.staff.status', OrganizationUser::STATUS_ACTIVE);

        $staffId = $response->json('data.staff.id');
        $this->assertDatabaseHas('organization_users', [
            'organization_id' => $organization->id,
            'user_id' => $staffId,
            'status' => OrganizationUser::STATUS_ACTIVE,
        ]);
        // The global account status is untouched by staff creation.
        $this->assertDatabaseHas('users', ['id' => $staffId, 'status' => User::STATUS_ACTIVE]);
    }

    // --- Deactivate / reactivate --------------------------------------

    public function test_owner_can_deactivate_a_staff_member(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $staff = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');

        $this->actingAs($owner, 'web')
            ->patchJson("/api/v1/staff/{$staff->id}", ['status' => OrganizationUser::STATUS_INACTIVE])
            ->assertOk()
            ->assertJsonPath('data.staff.status', OrganizationUser::STATUS_INACTIVE);

        $this->assertSame(OrganizationUser::STATUS_INACTIVE, $this->membership($organization, $staff)->status);
    }

    public function test_manager_with_manage_users_can_deactivate_a_staff_member(): void
    {
        [$organization, , $restaurant] = $this->createTenant();
        $manager = $this->createStaff($organization, $restaurant, 'manager', 'M-1');
        $staff = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');

        $this->actingAs($manager, 'web')
            ->patchJson("/api/v1/staff/{$staff->id}", ['status' => OrganizationUser::STATUS_INACTIVE])
            ->assertOk()
            ->assertJsonPath('data.staff.status', OrganizationUser::STATUS_INACTIVE);
    }

    public function test_owner_can_reactivate_an_inactive_staff_member(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $staff = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');
        $this->membership($organization, $staff)->update(['status' => OrganizationUser::STATUS_INACTIVE]);

        $this->actingAs($owner, 'web')
            ->patchJson("/api/v1/staff/{$staff->id}", ['status' => OrganizationUser::STATUS_ACTIVE])
            ->assertOk()
            ->assertJsonPath('data.staff.status', OrganizationUser::STATUS_ACTIVE);

        $this->assertSame(OrganizationUser::STATUS_ACTIVE, $this->membership($organization, $staff)->status);
    }

    public function test_deactivation_persists_across_reload(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $staff = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');

        $this->actingAs($owner, 'web')
            ->patchJson("/api/v1/staff/{$staff->id}", ['status' => OrganizationUser::STATUS_INACTIVE])
            ->assertOk();

        $this->actingAs($owner, 'web')
            ->getJson("/api/v1/staff/{$staff->id}")
            ->assertOk()
            ->assertJsonPath('data.staff.status', OrganizationUser::STATUS_INACTIVE);

        $this->actingAs($owner, 'web')
            ->getJson('/api/v1/staff')
            ->assertOk()
            ->assertJsonPath('data.staff.0.status', OrganizationUser::STATUS_INACTIVE);
    }

    public function test_invalid_status_value_is_rejected(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $staff = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');

        // Neither an arbitrary string nor the GLOBAL enum's own value
        // (suspended) is a valid tenant-level status.
        $this->actingAs($owner, 'web')
            ->patchJson("/api/v1/staff/{$staff->id}", ['status' => 'suspended'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $this->actingAs($owner, 'web')
            ->patchJson("/api/v1/staff/{$staff->id}", ['status' => 'disabled'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');
    }

    // --- Staff API never touches users.status --------------------------

    public function test_staff_deactivation_never_changes_the_global_user_status(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $staff = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');

        $this->actingAs($owner, 'web')
            ->patchJson("/api/v1/staff/{$staff->id}", ['status' => OrganizationUser::STATUS_INACTIVE])
            ->assertOk();

        $this->assertSame(User::STATUS_ACTIVE, $staff->fresh()->status);
    }

    public function test_tenant_cannot_use_staff_api_to_reactivate_a_platform_suspended_user(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $staff = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');
        $staff->update(['status' => User::STATUS_SUSPENDED]);

        // The owner sends `status: active` — the only value the Staff API
        // even understands is the tenant-level one, and it must never
        // reach users.status.
        $this->actingAs($owner, 'web')
            ->patchJson("/api/v1/staff/{$staff->id}", ['status' => OrganizationUser::STATUS_ACTIVE])
            ->assertOk()
            ->assertJsonPath('data.staff.status', OrganizationUser::STATUS_ACTIVE);

        $this->assertSame(User::STATUS_SUSPENDED, $staff->fresh()->status);
    }

    public function test_platform_suspension_blocks_access_even_with_an_active_tenant_membership(): void
    {
        [$organization, , $restaurant] = $this->createTenant();
        $staff = $this->createStaff($organization, $restaurant, 'waiter', 'W-1', email: 'globally-suspended@example.com');
        $staff->update(['status' => User::STATUS_SUSPENDED]);

        // Membership is (and stays) active — only the global flag blocks.
        $this->assertSame(OrganizationUser::STATUS_ACTIVE, $this->membership($organization, $staff)->status);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'globally-suspended@example.com',
            'password' => 'password123',
        ])
            ->assertForbidden()
            ->assertExactJson(['message' => 'This account has been suspended.']);

        $this->assertGuest('web');
    }

    // --- Login / existing session / Auth Context / realtime ------------

    public function test_deactivated_staff_cannot_log_in(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $staff = $this->createStaff($organization, $restaurant, 'waiter', 'W-1', email: 'inactive-staff@example.com');

        $this->actingAs($owner, 'web')
            ->patchJson("/api/v1/staff/{$staff->id}", ['status' => OrganizationUser::STATUS_INACTIVE])
            ->assertOk();

        // Login itself is global and unaffected by org membership — this
        // user still fails to authenticate here only because they belong
        // to no OTHER organization at all (see the multi-org test below
        // for the case where a valid context exists elsewhere).
        $this->postJson('/api/v1/auth/login', [
            'email' => 'inactive-staff@example.com',
            'password' => 'password123',
        ])->assertOk();

        $this->assertAuthenticatedAs($staff, 'web');
    }

    public function test_deactivating_an_already_authenticated_staff_member_blocks_their_next_tenant_request(): void
    {
        [$organization, , $restaurant] = $this->createTenant();
        $staff = $this->createStaff($organization, $restaurant, 'waiter', 'W-1', email: 'active-session@example.com');

        $this->postJson('/api/v1/auth/login', [
            'email' => 'active-session@example.com',
            'password' => 'password123',
        ])->assertOk();

        // /api/v1/organization only requires (active) organization
        // membership, no manage_users, so any active staff member
        // (waiter included) can reach it.
        Auth::forgetGuards();
        $this->getJson('/api/v1/organization')->assertOk();

        // Someone else (an owner/manager) deactivates this staff member —
        // exercised as a direct state change here, since the PATCH
        // endpoint's own authorization/behavior already has dedicated
        // coverage above; this test's only concern is the already-open
        // session's fate.
        $this->membership($organization, $staff)->update(['status' => OrganizationUser::STATUS_INACTIVE]);

        // Same already-authenticated session, next tenant request:
        // ResolveTenant re-derives membership fresh from the database
        // every request, so this is blocked immediately.
        Auth::forgetGuards();
        $this->getJson('/api/v1/organization')
            ->assertForbidden()
            ->assertJsonPath('message', 'The authenticated user has no active organization.');
    }

    public function test_deactivated_staff_organization_is_excluded_from_auth_context(): void
    {
        [$organization, , $restaurant] = $this->createTenant();
        $staff = $this->createStaff($organization, $restaurant, 'waiter', 'W-1', email: 'context-check@example.com');

        $this->postJson('/api/v1/auth/login', [
            'email' => 'context-check@example.com',
            'password' => 'password123',
        ])->assertOk();

        Auth::forgetGuards();
        $this->getJson('/api/v1/auth/context')
            ->assertOk()
            ->assertJsonCount(1, 'data.organizations');

        $this->membership($organization, $staff)->update(['status' => OrganizationUser::STATUS_INACTIVE]);

        // /auth/me still succeeds (global identity, unaffected — this is
        // NOT a platform suspension), but the deactivated organization no
        // longer appears in the operational context.
        Auth::forgetGuards();
        $this->getJson('/api/v1/auth/me')->assertOk();

        Auth::forgetGuards();
        $this->getJson('/api/v1/auth/context')
            ->assertOk()
            ->assertJsonCount(0, 'data.organizations');
    }

    public function test_deactivated_staff_cannot_authenticate_the_restaurant_realtime_channel(): void
    {
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => 'test-app-id',
        ]);
        require base_path('routes/channels.php');

        [$organization, , $restaurant] = $this->createTenant();
        $staff = $this->createStaff($organization, $restaurant, 'waiter', 'W-1', email: 'realtime-check@example.com');

        $this->postJson('/api/v1/auth/login', [
            'email' => 'realtime-check@example.com',
            'password' => 'password123',
        ])->assertOk();

        // Sanity check: active staff is authorized before deactivation.
        Auth::forgetGuards();
        $this->authorizeChannel($restaurant->id)->assertOk();

        $this->membership($organization, $staff)->update(['status' => OrganizationUser::STATUS_INACTIVE]);

        Auth::forgetGuards();
        $this->authorizeChannel($restaurant->id)->assertForbidden();
    }

    // --- Multi-organization ---------------------------------------------

    public function test_user_active_in_one_organization_and_inactive_in_another_keeps_the_active_ones_access(): void
    {
        [$organizationA, , $restaurantA] = $this->createTenant();
        [$organizationB, , $restaurantB] = $this->createTenant();

        // Same physical user, staff in both organizations.
        $staff = $this->createStaff($organizationA, $restaurantA, 'waiter', 'W-A', email: 'multiorg@example.com');
        $organizationB->users()->attach($staff->id);
        $restaurantB->users()->attach($staff->id, ['sub_id' => 'W-B']);
        UserRole::query()->create([
            'user_id' => $staff->id,
            'role_id' => Role::query()->where('slug', 'waiter')->firstOrFail()->id,
            'organization_id' => $organizationB->id,
            'restaurant_id' => $restaurantB->id,
        ]);

        // Deactivate only in Organization B.
        $this->membership($organizationB, $staff)->update(['status' => OrganizationUser::STATUS_INACTIVE]);
        $this->assertSame(OrganizationUser::STATUS_ACTIVE, $this->membership($organizationA, $staff)->status);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'multiorg@example.com',
            'password' => 'password123',
        ])->assertOk();

        // Auth Context: only the active organization (A) is listed.
        Auth::forgetGuards();
        $response = $this->getJson('/api/v1/auth/context')->assertOk();
        $orgIds = collect($response->json('data.organizations'))->pluck('id');
        $this->assertTrue($orgIds->contains($organizationA->id));
        $this->assertFalse($orgIds->contains($organizationB->id));

        // Realtime: Organization A's restaurant is reachable, B's is not.
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => 'test-app-id',
        ]);
        require base_path('routes/channels.php');

        Auth::forgetGuards();
        $this->authorizeChannel($restaurantA->id)->assertOk();

        Auth::forgetGuards();
        $this->authorizeChannel($restaurantB->id)->assertForbidden();
    }

    // --- Self-deactivation / owner / cross-tenant -----------------------

    public function test_manager_cannot_deactivate_themselves(): void
    {
        [$organization, , $restaurant] = $this->createTenant();
        $manager = $this->createStaff($organization, $restaurant, 'manager', 'M-1');

        $this->actingAs($manager, 'web')
            ->patchJson("/api/v1/staff/{$manager->id}", ['status' => OrganizationUser::STATUS_INACTIVE])
            ->assertForbidden();

        $this->assertSame(OrganizationUser::STATUS_ACTIVE, $this->membership($organization, $manager)->status);
    }

    public function test_owner_has_no_restaurant_users_row_and_is_therefore_unreachable_via_the_staff_api(): void
    {
        // The owner never appears in the Staff API at all (see
        // StaffController::staffQuery's docblock) — this is the existing,
        // preserved behavior: an owner can never be targeted for
        // deactivation through this endpoint, by themselves or anyone
        // else, because they simply do not resolve as a "staff" row.
        [$organization, $owner] = $this->createTenant();

        $this->actingAs($owner, 'web')
            ->patchJson("/api/v1/staff/{$owner->id}", ['status' => OrganizationUser::STATUS_INACTIVE])
            ->assertNotFound();

        $this->assertSame(OrganizationUser::STATUS_ACTIVE, $this->membership($organization, $owner)->status);
    }

    public function test_organization_a_cannot_deactivate_staff_of_organization_b(): void
    {
        [, $ownerA] = $this->createTenant();
        [$organizationB, , $restaurantB] = $this->createTenant();
        $staffB = $this->createStaff($organizationB, $restaurantB, 'waiter', 'W-1');

        $this->actingAs($ownerA, 'web')
            ->patchJson("/api/v1/staff/{$staffB->id}", ['status' => OrganizationUser::STATUS_INACTIVE])
            ->assertNotFound();

        $this->assertSame(OrganizationUser::STATUS_ACTIVE, $this->membership($organizationB, $staffB)->status);
    }

    // --- History preserved ------------------------------------------------

    public function test_deactivate_then_reactivate_preserves_roles_and_restaurant_assignments(): void
    {
        [$organization, $owner, $restaurantA] = $this->createTenant();
        $restaurantB = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $staff = $this->createStaffAcrossRestaurants($organization, [$restaurantA, $restaurantB], 'waiter', $owner);

        $this->actingAs($owner, 'web')
            ->patchJson("/api/v1/staff/{$staff->id}", ['status' => OrganizationUser::STATUS_INACTIVE])
            ->assertOk();

        $this->assertSame(2, RestaurantUser::query()->where('user_id', $staff->id)->count());
        $this->assertSame(2, UserRole::query()->where('user_id', $staff->id)->count());

        $this->actingAs($owner, 'web')
            ->patchJson("/api/v1/staff/{$staff->id}", ['status' => OrganizationUser::STATUS_ACTIVE])
            ->assertOk();

        $this->assertSame(2, RestaurantUser::query()->where('user_id', $staff->id)->count());
        $this->assertSame(2, UserRole::query()->where('user_id', $staff->id)->count());
        $this->assertDatabaseHas('restaurant_users', ['user_id' => $staff->id, 'restaurant_id' => $restaurantA->id]);
        $this->assertDatabaseHas('restaurant_users', ['user_id' => $staff->id, 'restaurant_id' => $restaurantB->id]);
    }

    // --- Audit -------------------------------------------------------------

    public function test_deactivation_records_an_audit_event_with_status_change(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $staff = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');

        $this->actingAs($owner, 'web')
            ->patchJson("/api/v1/staff/{$staff->id}", ['status' => OrganizationUser::STATUS_INACTIVE])
            ->assertOk();

        $log = AuditLog::query()->where('event', AuditLog::EVENT_STAFF_UPDATED)->first();

        $this->assertNotNull($log);
        $this->assertEquals(
            ['old' => OrganizationUser::STATUS_ACTIVE, 'new' => OrganizationUser::STATUS_INACTIVE],
            $log->changes['status'],
        );
    }

    public function test_no_op_status_update_records_no_audit_event(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $staff = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');

        $this->actingAs($owner, 'web')
            ->patchJson("/api/v1/staff/{$staff->id}", ['status' => OrganizationUser::STATUS_ACTIVE])
            ->assertOk();

        $this->assertSame(0, AuditLog::query()->where('event', AuditLog::EVENT_STAFF_UPDATED)->count());
    }
}
