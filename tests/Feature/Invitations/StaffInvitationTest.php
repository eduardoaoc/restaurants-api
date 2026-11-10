<?php

namespace Tests\Feature\Invitations;

use App\Models\AuditLog;
use App\Models\Invitation;
use App\Models\OrganizationUser;
use App\Models\Restaurant;
use App\Models\RestaurantUser;
use App\Models\User;
use App\Models\UserRole;
use App\Notifications\InvitationNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\InteractsWithInvitations;
use Tests\Concerns\InteractsWithPlatform;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class StaffInvitationTest extends TestCase
{
    use InteractsWithInvitations, InteractsWithPlatform, InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
        $this->seedPlatformRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
        config(['invitations.web_url' => 'http://localhost:5173']);
        Notification::fake();
    }

    /**
     * @param  array<int, array{restaurant_id: int, sub_id: string}>  $assignments
     */
    private function invite(User $actor, array $assignments, string $role = 'waiter', string $email = 'Ana@Example.com'): TestResponse
    {
        return $this->actingAs($actor, 'web')->postJson('/api/v1/staff/invitations', [
            'name' => 'Ana',
            'email' => $email,
            'role' => $role,
            'restaurant_assignments' => $assignments,
        ]);
    }

    public function test_owner_invites_a_waiter_without_creating_any_account(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();

        $this->invite($owner, [['restaurant_id' => $restaurant->id]])
            ->assertCreated()
            ->assertJsonPath('data.invitation.type', 'staff')
            ->assertJsonPath('data.invitation.email', 'ana@example.com')
            ->assertJsonPath('data.invitation.role', 'waiter')
            ->assertJsonPath('data.invitation.state', 'pending')
            ->assertJsonPath('data.invitation.restaurants.0.name', $restaurant->name)
            ->assertJsonMissingPath('data.invitation.restaurants.0.sub_id')
            ->assertJsonMissingPath('data.invitation.token_hash');

        $this->assertSame(0, User::query()->where('email', 'ana@example.com')->count());
        Notification::assertSentTimes(InvitationNotification::class, 1);

        $this->getJson('/api/v1/staff/invitations')->assertOk()->assertJsonCount(1, 'data.invitations');
    }

    public function test_invited_new_staff_defines_password_and_gets_only_the_invited_restaurant(): void
    {
        [$organization, $owner, $restaurantA] = $this->createTenant();
        $restaurantB = Restaurant::factory()->create(['organization_id' => $organization->id]);

        $this->invite($owner, [['restaurant_id' => $restaurantA->id]], 'kitchen')->assertCreated();
        $token = $this->mintToken(Invitation::query()->sole());

        $this->freshBrowser();
        $this->postJson('/api/v1/invitations/preview', ['token' => $token])
            ->assertOk()->assertJsonPath('data.invitation.role', 'kitchen')
            ->assertJsonPath('data.invitation.restaurants', [['name' => $restaurantA->name]]);
        $this->acceptNew($token, 'Ana Cocina', 'kitchen-pass-1')->assertOk();

        $ana = User::query()->where('email', 'ana@example.com')->sole();
        $this->assertTrue(Hash::check('kitchen-pass-1', $ana->password));
        $this->assertSame([$restaurantA->id], $ana->restaurants()->pluck('restaurants.id')->all());
        $this->assertSame('E-'.$ana->id, $ana->restaurants()->first()->pivot->sub_id);
        $grant = UserRole::query()->where('user_id', $ana->id)->sole();
        $this->assertSame('kitchen', $grant->role->slug);
        $this->assertSame($restaurantA->id, $grant->restaurant_id);

        $this->sameBrowserNextRequest();
        $context = $this->getJson('/api/v1/auth/context')->assertOk();
        $restaurants = $context->json('data.organizations.0.restaurants');
        $this->assertCount(1, $restaurants);
        $this->assertSame(['update_kitchen_status'], $restaurants[0]['permissions']);
        $this->assertSame([], $context->json('data.organizations.0.permissions'));

        // Kitchen holds no manage_users: the Staff area stays closed.
        $this->sameBrowserNextRequest();
        $this->getJson('/api/v1/staff')->assertForbidden();
        $this->getJson("/api/v1/restaurants/{$restaurantB->id}")->assertNotFound();

        // It shows up in the owner's roster like any staff member.
        $this->freshBrowser();
        $this->actingAs($owner, 'web')->getJson('/api/v1/staff')->assertOk()->assertJsonPath('data.staff.0.email', 'ana@example.com');
    }

    public function test_owner_role_and_platform_roles_cannot_be_invited(): void
    {
        [, $owner, $restaurant] = $this->createTenant();

        foreach (['owner', 'super_admin', 'admin', 'root'] as $role) {
            $this->invite($owner, [['restaurant_id' => $restaurant->id]], $role)
                ->assertUnprocessable()->assertJsonValidationErrors('role');
        }

        $this->assertSame(0, Invitation::query()->count());
    }

    public function test_payload_cannot_inject_permissions_or_organization(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        [$otherOrganization] = $this->createTenant();

        $this->actingAs($owner, 'web')->postJson('/api/v1/staff/invitations', [
            'name' => 'Ana', 'email' => 'ana@example.com', 'role' => 'waiter',
            'restaurant_assignments' => [['restaurant_id' => $restaurant->id]],
            'organization_id' => $otherOrganization->id,
            'permissions' => ['manage_users', 'manage_organization'],
            'type' => 'owner',
        ])->assertCreated();

        $invitation = Invitation::query()->sole();
        $this->assertSame($organization->id, $invitation->organization_id);
        $this->assertSame(Invitation::TYPE_STAFF, $invitation->type);
        $this->assertEquals([['restaurant_id' => $restaurant->id]], $invitation->restaurant_assignments); // sub_id/role in input are dropped
    }

    public function test_restaurant_of_another_tenant_is_refused(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        [, , $foreignRestaurant] = $this->createTenant();

        $this->invite($owner, [['restaurant_id' => $foreignRestaurant->id]])
            ->assertUnprocessable()->assertJsonValidationErrors('restaurant_assignments.0.restaurant_id');
        $this->invite($owner, [['restaurant_id' => $restaurant->id], ['restaurant_id' => $foreignRestaurant->id]])
            ->assertUnprocessable();

        $this->assertSame(0, Invitation::query()->count());
    }

    public function test_restaurant_scoped_manager_cannot_invite_into_a_restaurant_outside_their_scope(): void
    {
        [$organization, , $restaurantA] = $this->createTenant();
        $restaurantB = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $manager = $this->createStaff($organization, $restaurantA, 'manager', 'M-1');

        $this->invite($manager, [['restaurant_id' => $restaurantB->id]])->assertNotFound();
        $this->invite($manager, [['restaurant_id' => $restaurantA->id], ['restaurant_id' => $restaurantB->id]])->assertNotFound();
        $this->assertSame(0, Invitation::query()->count());

        $this->invite($manager, [['restaurant_id' => $restaurantA->id]])->assertCreated();
    }

    public function test_staff_without_manage_users_cannot_invite(): void
    {
        [$organization, , $restaurant] = $this->createTenant();

        foreach (['waiter', 'kitchen', 'cashier'] as $index => $role) {
            $staff = $this->createStaff($organization, $restaurant, $role, 'S-'.$index);
            $this->freshBrowser();
            $this->invite($staff, [['restaurant_id' => $restaurant->id]], 'waiter', "x{$index}@example.com")
                ->assertForbidden();
            $this->getJson('/api/v1/staff/invitations')->assertForbidden();
        }

        $this->assertSame(0, Invitation::query()->count());
    }

    public function test_existing_member_and_deactivated_member_are_not_invited_again(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $active = $this->createStaff($organization, $restaurant, 'waiter', 'W-1', 'active@example.com');
        $inactive = $this->createStaff($organization, $restaurant, 'waiter', 'W-2', 'inactive@example.com');
        OrganizationUser::query()->where('user_id', $inactive->id)->update(['status' => OrganizationUser::STATUS_INACTIVE]);

        $this->invite($owner, [['restaurant_id' => $restaurant->id]], 'waiter', 'ACTIVE@example.com')
            ->assertStatus(409)->assertJsonPath('error.code', 'STAFF_ALREADY_MEMBER');
        $this->invite($owner, [['restaurant_id' => $restaurant->id]], 'waiter', 'inactive@example.com')
            ->assertStatus(409)->assertJsonPath('error.code', 'STAFF_MEMBERSHIP_INACTIVE');

        $this->assertSame(OrganizationUser::STATUS_INACTIVE, OrganizationUser::query()->where('user_id', $inactive->id)->value('status'));
    }

    public function test_second_pending_invitation_for_same_e_mail_is_refused(): void
    {
        [, $owner, $restaurant] = $this->createTenant();

        $first = $this->invite($owner, [['restaurant_id' => $restaurant->id]])->assertCreated();
        $this->invite($owner, [['restaurant_id' => $restaurant->id]])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'INVITATION_ALREADY_PENDING')
            ->assertJsonPath('error.invitation_id', $first->json('data.invitation.id'));

        $this->assertSame(1, Invitation::query()->count());
    }

    public function test_invitation_carries_no_employee_code_even_if_the_client_sends_one(): void
    {
        [, $owner, $restaurant] = $this->createTenant();

        $this->actingAs($owner, 'web')->postJson('/api/v1/staff/invitations', [
            'name' => 'Ana', 'email' => 'ana@example.com', 'role' => 'waiter',
            'restaurant_assignments' => [['restaurant_id' => $restaurant->id, 'sub_id' => 'CHOSEN-1']],
        ])->assertCreated();

        $this->assertSame([['restaurant_id' => $restaurant->id]], Invitation::query()->sole()->restaurant_assignments);
    }

    public function test_two_pending_invitations_for_one_restaurant_get_distinct_codes_on_acceptance(): void
    {
        [, $owner, $restaurant] = $this->createTenant();

        $this->invite($owner, [['restaurant_id' => $restaurant->id]])->assertCreated();
        $this->invite($owner, [['restaurant_id' => $restaurant->id]], 'waiter', 'other@example.com')->assertCreated();

        foreach (Invitation::query()->orderBy('id')->get() as $invitation) {
            $this->freshBrowser();
            $this->acceptNew($this->mintToken($invitation), 'Person', 'password-123')->assertOk();
        }

        $codes = RestaurantUser::query()->where('restaurant_id', $restaurant->id)->pluck('sub_id')->all();
        $this->assertCount(2, array_unique(array_filter($codes, fn ($c) => str_starts_with($c, 'E-'))));
    }

    public function test_account_from_another_organization_is_linked_only_by_accepting(): void
    {
        [$organizationA, $ownerA, $restaurantA] = $this->createTenant();
        [$organizationB, , $restaurantB] = $this->createTenant();
        $shared = $this->createStaff($organizationB, $restaurantB, 'manager', 'MB-1', 'shared@example.com', 'Shared Person');
        $hash = $shared->password;

        // Same answer as for a brand-new address: nothing about B leaks.
        $response = $this->invite($ownerA, [['restaurant_id' => $restaurantA->id]], 'waiter', 'shared@example.com')->assertCreated();
        $this->assertStringNotContainsString($organizationB->name, $response->getContent());
        $this->assertFalse($shared->organizations()->whereKey($organizationA->id)->exists());

        $token = $this->mintToken(Invitation::query()->sole());
        $this->freshBrowser();
        $preview = $this->postJson('/api/v1/invitations/preview', ['token' => $token])->assertOk();
        $this->assertStringNotContainsString($organizationB->name, $preview->getContent());

        $this->acceptExisting($token, 'password123')->assertOk();

        $shared->refresh();
        $this->assertSame('Shared Person', $shared->name);
        $this->assertSame($hash, $shared->password);

        // Manager in B never becomes manager in A.
        $grantA = UserRole::query()->where('user_id', $shared->id)->where('organization_id', $organizationA->id)->sole();
        $this->assertSame('waiter', $grantA->role->slug);
        $this->sameBrowserNextRequest();
        $context = collect($this->getJson('/api/v1/auth/context')->json('data.organizations'))->keyBy('id');
        $this->assertNotContains('manage_users', $context[$organizationA->id]['restaurants'][0]['permissions']);
        $this->assertContains('manage_users', $context[$organizationB->id]['restaurants'][0]['permissions']);

        // Owner A cannot rewrite the global identity of a multi-org account.
        $this->freshBrowser();
        $this->actingAs($ownerA, 'web')->patchJson("/api/v1/staff/{$shared->id}", ['email' => 'hijack@example.com'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_restaurant_removed_from_the_organization_blocks_acceptance(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $other = Restaurant::factory()->create();
        $this->invite($owner, [['restaurant_id' => $restaurant->id]])->assertCreated();
        $token = $this->mintToken(Invitation::query()->sole());

        // The restaurant left the organization after the invitation was sent.
        DB::table('restaurants')->where('id', $restaurant->id)->update(['organization_id' => $other->organization_id]);

        $this->freshBrowser();
        $this->acceptNew($token)->assertStatus(409)->assertJsonPath('error.code', 'INVITATION_ASSIGNMENT_CONFLICT');
        $this->assertSame(0, User::query()->where('email', 'ana@example.com')->count());
        $this->assertTrue(Invitation::query()->sole()->isPending());
        $this->assertNotNull($organization);
    }

    public function test_existing_account_keeps_the_same_user_id_when_linked_to_another_restaurant(): void
    {
        [$organization, $owner, $restaurantA] = $this->createTenant();
        $restaurantB = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $this->createStaff($organization, $restaurantA, 'waiter', 'W-OLD', 'ana@example.com', 'Ana Existing');

        // An account of ANOTHER organization is the existing-account case.
        [$organizationB, , $restaurantOfB] = $this->createTenant();
        $other = $this->createStaff($organizationB, $restaurantOfB, 'manager', 'MB-1', 'shared@example.com', 'Shared Person');
        $usersBefore = User::query()->count();

        $this->invite($owner, [['restaurant_id' => $restaurantB->id]], 'waiter', 'shared@example.com')->assertCreated();
        $this->freshBrowser();
        $this->acceptExisting($this->mintToken(Invitation::query()->sole()), 'password123')->assertOk();

        $this->assertSame($usersBefore, User::query()->count(), 'no new identity may be created');
        $this->assertSame(1, User::query()->where('email', 'shared@example.com')->count());
        $this->assertSame($other->id, User::query()->where('email', 'shared@example.com')->value('id'));
        $this->assertDatabaseHas('restaurant_users', ['user_id' => $other->id, 'restaurant_id' => $restaurantB->id, 'sub_id' => 'E-'.$other->id]);
        // Memberships elsewhere keep their value.
        $this->assertDatabaseHas('restaurant_users', ['user_id' => $other->id, 'restaurant_id' => $restaurantOfB->id, 'sub_id' => 'MB-1']);
    }

    public function test_acceptance_audit_actor_is_the_accepting_user_and_staff_id_is_in_metadata(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $this->invite($owner, [['restaurant_id' => $restaurant->id]])->assertCreated();
        $created = AuditLog::query()->where('event', 'invitation.created')->sole();
        $this->assertSame($owner->id, $created->actor_user_id);

        $this->freshBrowser();
        $this->acceptNew($this->mintToken(Invitation::query()->sole()))->assertOk();

        $ana = User::query()->where('email', 'ana@example.com')->sole();
        $accepted = AuditLog::query()->where('event', 'invitation.accepted')->sole();
        $this->assertSame($ana->id, $accepted->actor_user_id);
        $this->assertSame($ana->id, $accepted->metadata['user_id']);
    }

    public function test_restaurant_scoped_manager_cannot_resend_or_revoke_an_invitation_reaching_other_restaurants(): void
    {
        [$organization, $owner, $restaurantA] = $this->createTenant();
        $restaurantB = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $manager = $this->createStaff($organization, $restaurantA, 'manager', 'M-1');

        $this->invite($owner, [['restaurant_id' => $restaurantA->id], ['restaurant_id' => $restaurantB->id]])->assertCreated();
        $invitation = Invitation::query()->sole();

        $this->freshBrowser();
        $this->actingAs($manager, 'web')->getJson('/api/v1/staff/invitations')->assertOk()->assertJsonCount(0, 'data.invitations');
        $this->postJson("/api/v1/staff/invitations/{$invitation->id}/revoke")->assertNotFound();
        $this->travel(61)->seconds();
        $this->postJson("/api/v1/staff/invitations/{$invitation->id}/resend")->assertNotFound();
        $this->assertTrue($invitation->fresh()->isPending());
    }

    public function test_other_organization_cannot_see_or_touch_the_invitation(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        [, $otherOwner] = $this->createTenant();
        $this->invite($owner, [['restaurant_id' => $restaurant->id]])->assertCreated();
        $invitation = Invitation::query()->sole();

        $this->freshBrowser();
        $this->actingAs($otherOwner, 'web')->getJson('/api/v1/staff/invitations')->assertOk()->assertJsonCount(0, 'data.invitations');
        $this->postJson("/api/v1/staff/invitations/{$invitation->id}/revoke")->assertNotFound();
        $this->postJson("/api/v1/staff/invitations/{$invitation->id}/resend")->assertNotFound();
    }

    public function test_owner_invitations_are_not_reachable_through_the_staff_api(): void
    {
        $admin = $this->createPlatformAdmin();
        $this->onboard($admin, $this->newOrganizationPayload())->assertCreated();
        $ownerInvitation = Invitation::query()->sole();
        $token = $this->mintToken($ownerInvitation);
        $this->freshBrowser();
        $this->acceptNew($token)->assertOk();
        $owner = User::query()->where('email', 'pepe@example.com')->sole();

        $other = Invitation::query()->create([
            ...$ownerInvitation->only(['organization_id', 'type', 'role_id', 'locale', 'invited_by_type']),
            'email' => 'second-owner@example.com', 'token_hash' => str_repeat('b', 64), 'status' => 'pending', 'expires_at' => now()->addDay(),
        ]);

        $this->sameBrowserNextRequest();
        $this->getJson('/api/v1/staff/invitations')->assertOk()->assertJsonCount(0, 'data.invitations');
        $this->postJson("/api/v1/staff/invitations/{$other->id}/revoke")->assertNotFound();
        $this->assertSame($owner->id, $ownerInvitation->fresh()->accepted_user_id);
    }

    public function test_staff_invitation_audit_has_no_secrets(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $this->invite($owner, [['restaurant_id' => $restaurant->id]])->assertCreated();
        $token = $this->mintToken(Invitation::query()->sole());

        $created = AuditLog::query()->where('event', AuditLog::EVENT_INVITATION_CREATED)->sole();
        $this->assertSame(AuditLog::ACTOR_USER, $created->actor_type);
        $this->assertSame($owner->id, $created->actor_user_id);
        $this->assertSame([$restaurant->id], $created->metadata['restaurant_ids']);

        $dump = json_encode(AuditLog::query()->get()->toArray());
        $this->assertStringNotContainsString('ana@example.com', $dump);
        $this->assertStringNotContainsString($token, $dump);
    }
}
