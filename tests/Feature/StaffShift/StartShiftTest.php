<?php

namespace Tests\Feature\StaffShift;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithStaffShifts;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bloco 3 — Staff Shift: starting a shift.
 */
class StartShiftTest extends TestCase
{
    use InteractsWithStaffShifts, InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    public function test_staff_starts_own_shift(): void
    {
        [$organization, , $restaurant] = $this->createTenant();
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');

        $this->actingAs($waiter, 'web')
            ->postJson("/api/v1/restaurants/{$restaurant->id}/staff-shifts", ['user_id' => $waiter->id])
            ->assertCreated()
            ->assertJsonPath('data.staff_shift.user.id', $waiter->id)
            ->assertJsonPath('data.staff_shift.restaurant_id', $restaurant->id)
            ->assertJsonPath('data.staff_shift.is_active', true)
            ->assertJsonPath('data.staff_shift.ended_at', null)
            ->assertJsonPath('data.staff_shift.role', 'waiter');

        $this->assertDatabaseHas('staff_shifts', [
            'restaurant_id' => $restaurant->id,
            'user_id' => $waiter->id,
            'started_by_user_id' => $waiter->id,
            'ended_at' => null,
        ]);
    }

    public function test_manager_starts_staff_shift(): void
    {
        [$organization, , $restaurant] = $this->createTenant();
        $manager = $this->createStaff($organization, $restaurant, 'manager', 'M-1');
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');

        $this->actingAs($manager, 'web')
            ->postJson("/api/v1/restaurants/{$restaurant->id}/staff-shifts", ['user_id' => $waiter->id])
            ->assertCreated()
            ->assertJsonPath('data.staff_shift.user.id', $waiter->id);

        $this->assertDatabaseHas('staff_shifts', [
            'restaurant_id' => $restaurant->id,
            'user_id' => $waiter->id,
            'started_by_user_id' => $manager->id,
        ]);
    }

    public function test_owner_starts_staff_shift(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $kitchen = $this->createStaff($organization, $restaurant, 'kitchen', 'K-1');

        $this->actingAs($owner, 'web')
            ->postJson("/api/v1/restaurants/{$restaurant->id}/staff-shifts", ['user_id' => $kitchen->id])
            ->assertCreated()
            ->assertJsonPath('data.staff_shift.user.id', $kitchen->id)
            ->assertJsonPath('data.staff_shift.role', 'kitchen');

        $this->assertDatabaseHas('staff_shifts', [
            'restaurant_id' => $restaurant->id,
            'user_id' => $kitchen->id,
            'started_by_user_id' => $owner->id,
        ]);
    }

    public function test_started_at_is_populated_and_ended_at_is_null(): void
    {
        [$organization, , $restaurant] = $this->createTenant();
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');

        $response = $this->actingAs($waiter, 'web')
            ->postJson("/api/v1/restaurants/{$restaurant->id}/staff-shifts", ['user_id' => $waiter->id])
            ->assertCreated();

        $this->assertNotNull($response->json('data.staff_shift.started_at'));
        $this->assertNull($response->json('data.staff_shift.ended_at'));
    }

    public function test_missing_user_id_returns_validation_error(): void
    {
        [, $owner, $restaurant] = $this->createTenant();

        $this->actingAs($owner, 'web')
            ->postJson("/api/v1/restaurants/{$restaurant->id}/staff-shifts", [])
            ->assertStatus(422);
    }

    public function test_nonexistent_user_id_returns_validation_error(): void
    {
        [, $owner, $restaurant] = $this->createTenant();

        $this->actingAs($owner, 'web')
            ->postJson("/api/v1/restaurants/{$restaurant->id}/staff-shifts", ['user_id' => 999999])
            ->assertStatus(422);
    }
}
