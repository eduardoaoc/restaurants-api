<?php

namespace Tests\Feature\Tenancy;

use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\Restaurant;
use App\Models\User;
use App\Support\Tenancy\ActiveOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Session-selected active organization (PUT /auth/active-organization) on
 * top of ResolveTenant's long-standing "lowest-id active membership" rule.
 */
class ActiveOrganizationTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    /**
     * Waiter in A (lower id), owner in B.
     *
     * @return array{0: Organization, 1: Restaurant, 2: Organization, 3: Restaurant, 4: User}
     */
    private function multiOrganizationUser(): array
    {
        [$organizationA, , $restaurantA] = $this->createTenant();
        [$organizationB, , $restaurantB] = $this->createTenant();
        $user = $this->createStaff($organizationA, $restaurantA, 'waiter', 'W-1', 'multi@example.com');
        $this->assignRole($user, 'owner', $organizationB);

        return [$organizationA, $restaurantA, $organizationB, $restaurantB, $user];
    }

    public function test_default_stays_the_lowest_id_active_membership(): void
    {
        [$organizationA, $restaurantA, , , $user] = $this->multiOrganizationUser();

        $this->actingAs($user, 'web')->getJson('/api/v1/restaurants')->assertOk()
            ->assertJsonCount(1, 'data.restaurants')->assertJsonPath('data.restaurants.0.id', $restaurantA->id);
        $this->getJson('/api/v1/auth/context')->assertJsonPath('data.active_organization_id', $organizationA->id);
    }

    public function test_selecting_another_membership_switches_tenant_and_permissions(): void
    {
        [$organizationA, $restaurantA, $organizationB, $restaurantB, $user] = $this->multiOrganizationUser();

        $this->actingAs($user, 'web')->getJson('/api/v1/staff')->assertForbidden(); // waiter in A

        $this->putJson('/api/v1/auth/active-organization', ['organization_id' => $organizationB->id])
            ->assertOk()->assertJsonPath('data.active_organization_id', $organizationB->id);

        $this->getJson('/api/v1/restaurants')->assertOk()
            ->assertJsonCount(1, 'data.restaurants')->assertJsonPath('data.restaurants.0.id', $restaurantB->id);
        $this->getJson('/api/v1/staff')->assertOk(); // owner in B
        $this->getJson("/api/v1/restaurants/{$restaurantA->id}")->assertNotFound();
        $this->getJson('/api/v1/auth/context')->assertJsonPath('data.active_organization_id', $organizationB->id);

        // Switching back restores A's (narrower) authority — nothing inherited.
        $this->putJson('/api/v1/auth/active-organization', ['organization_id' => $organizationA->id])->assertOk();
        $this->getJson('/api/v1/staff')->assertForbidden();
    }

    public function test_cannot_select_an_organization_without_active_membership(): void
    {
        [$organizationA, , $organizationB, , $user] = $this->multiOrganizationUser();
        [$foreign] = $this->createTenant();

        $this->actingAs($user, 'web')->putJson('/api/v1/auth/active-organization', ['organization_id' => $foreign->id])->assertNotFound();
        $this->putJson('/api/v1/auth/active-organization', ['organization_id' => 999999])->assertNotFound();
        $this->putJson('/api/v1/auth/active-organization', ['organization_id' => 'abc'])->assertUnprocessable();

        OrganizationUser::query()->where('organization_id', $organizationB->id)->where('user_id', $user->id)
            ->update(['status' => OrganizationUser::STATUS_INACTIVE]);
        $this->putJson('/api/v1/auth/active-organization', ['organization_id' => $organizationB->id])->assertNotFound();
        $this->getJson('/api/v1/auth/context')->assertJsonPath('data.active_organization_id', $organizationA->id);
    }

    public function test_stale_preference_falls_back_when_membership_is_deactivated(): void
    {
        [$organizationA, $restaurantA, $organizationB, , $user] = $this->multiOrganizationUser();

        $this->actingAs($user, 'web')->putJson('/api/v1/auth/active-organization', ['organization_id' => $organizationB->id])->assertOk();
        OrganizationUser::query()->where('organization_id', $organizationB->id)->where('user_id', $user->id)
            ->update(['status' => OrganizationUser::STATUS_INACTIVE]);

        $this->getJson('/api/v1/restaurants')->assertOk()->assertJsonPath('data.restaurants.0.id', $restaurantA->id);
        $this->getJson('/api/v1/auth/context')->assertJsonPath('data.active_organization_id', $organizationA->id);
    }

    public function test_suspended_organization_cannot_be_selected_and_still_blocks_when_preferred(): void
    {
        [, , $organizationB, , $user] = $this->multiOrganizationUser();

        $this->actingAs($user, 'web')->putJson('/api/v1/auth/active-organization', ['organization_id' => $organizationB->id])->assertOk();
        $organizationB->update(['status' => Organization::STATUS_SUSPENDED]);

        $this->getJson('/api/v1/restaurants')->assertForbidden();
        $this->putJson('/api/v1/auth/active-organization', ['organization_id' => $organizationB->id])
            ->assertStatus(409)->assertJsonPath('error.code', 'ORGANIZATION_SUSPENDED');
    }

    public function test_session_value_alone_never_grants_a_foreign_organization(): void
    {
        [$organizationA, $restaurantA, , , $user] = $this->multiOrganizationUser();
        [$foreign] = $this->createTenant();

        $this->actingAs($user, 'web')
            ->withSession([ActiveOrganization::SESSION_KEY => $foreign->id])
            ->getJson('/api/v1/restaurants')->assertOk()
            ->assertJsonPath('data.restaurants.0.id', $restaurantA->id);
    }

    public function test_guest_cannot_select(): void
    {
        $this->putJson('/api/v1/auth/active-organization', ['organization_id' => 1])->assertUnauthorized();
    }
}
