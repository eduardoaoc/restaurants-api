<?php

namespace Tests\Feature\Staff;

use App\Models\PlatformRole;
use App\Models\PlatformRoleAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Concerns\InteractsWithPlatform;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * users.email is a global login identity: one tenant must not be able to
 * rewrite it for a person who also belongs to another organization or
 * holds a platform role.
 */
class StaffIdentityBoundaryTest extends TestCase
{
    use InteractsWithPlatform, InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
        $this->seedPlatformRolesAndPermissions();
    }

    public function test_email_of_staff_shared_with_another_organization_cannot_be_changed(): void
    {
        [$organizationA, $ownerA, $restaurantA] = $this->createTenant();
        [$organizationB, , $restaurantB] = $this->createTenant();

        $staff = $this->createStaff($organizationA, $restaurantA, 'waiter', 'W-1', 'shared@example.com');
        $organizationB->users()->attach($staff->id);
        $restaurantB->users()->attach($staff->id, ['sub_id' => 'W-9']);

        $this->actingAs($ownerA, 'web')
            ->patchJson("/api/v1/staff/{$staff->id}", ['email' => 'hijacked@example.com'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->assertSame('shared@example.com', $staff->fresh()->email);
    }

    public function test_email_of_a_platform_admin_member_cannot_be_changed(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $staff = $this->createStaff($organization, $restaurant, 'waiter', 'W-1', 'admin-member@example.com');
        PlatformRoleAssignment::query()->create([
            'user_id' => $staff->id,
            'platform_role_id' => PlatformRole::query()->where('slug', 'super_admin')->firstOrFail()->id,
        ]);

        $this->actingAs($owner, 'web')
            ->patchJson("/api/v1/staff/{$staff->id}", ['email' => 'hijacked@example.com'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->assertSame('admin-member@example.com', $staff->fresh()->email);
    }

    public function test_other_fields_of_a_shared_staff_member_can_still_be_updated(): void
    {
        [$organizationA, $ownerA, $restaurantA] = $this->createTenant();
        [$organizationB] = $this->createTenant();

        $staff = $this->createStaff($organizationA, $restaurantA, 'waiter', 'W-1', 'shared@example.com');
        $organizationB->users()->attach($staff->id);

        // Re-sending the unchanged email is not a change.
        $this->actingAs($ownerA, 'web')
            ->patchJson("/api/v1/staff/{$staff->id}", ['name' => 'Renamed', 'email' => 'shared@example.com'])
            ->assertOk();

        $this->assertSame('Renamed', $staff->fresh()->name);
    }

    public function test_email_of_staff_exclusive_to_this_organization_can_be_changed(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $staff = $this->createStaff($organization, $restaurant, 'waiter', 'W-1', 'solo@example.com');

        $this->actingAs($owner, 'web')
            ->patchJson("/api/v1/staff/{$staff->id}", ['email' => 'solo-new@example.com'])
            ->assertOk();

        $this->assertSame('solo-new@example.com', $staff->fresh()->email);
    }

    public function test_staff_writes_are_throttled_per_user(): void
    {
        [, $owner] = $this->createTenant();
        $cacheKey = md5('staff-writes'.'user:'.$owner->id);
        RateLimiter::clear($cacheKey);
        for ($i = 0; $i < 20; $i++) {
            RateLimiter::hit($cacheKey, 60);
        }

        $this->actingAs($owner, 'web')
            ->postJson('/api/v1/staff', ['email' => 'probe@example.com'])
            ->assertTooManyRequests();
        $this->actingAs($owner, 'web')
            ->patchJson('/api/v1/staff/1', ['email' => 'probe@example.com'])
            ->assertTooManyRequests();

        RateLimiter::clear($cacheKey);
    }
}
