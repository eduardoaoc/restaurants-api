<?php

namespace Tests\Feature\StaffShift;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithStaffShifts;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bloco 3 — Staff Shift: ending a shift. Ending an already-ended shift is
 * a 409, deliberately NOT idempotent — see EndStaffShiftAction/report.
 */
class EndShiftTest extends TestCase
{
    use InteractsWithStaffShifts, InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    public function test_staff_ends_own_shift(): void
    {
        [$organization, , $restaurant] = $this->createTenant();
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');
        $shift = $this->startShift($restaurant, $waiter, $waiter);

        $this->actingAs($waiter, 'web')
            ->postJson("/api/v1/staff-shifts/{$shift->id}/end")
            ->assertOk()
            ->assertJsonPath('data.staff_shift.is_active', false);

        $this->assertNotNull($shift->fresh()->ended_at);
        $this->assertDatabaseHas('staff_shifts', [
            'id' => $shift->id,
            'ended_by_user_id' => $waiter->id,
        ]);
    }

    public function test_manager_ends_staff_shift(): void
    {
        [$organization, , $restaurant] = $this->createTenant();
        $manager = $this->createStaff($organization, $restaurant, 'manager', 'M-1');
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');
        $shift = $this->startShift($restaurant, $waiter, $waiter);

        $this->actingAs($manager, 'web')
            ->postJson("/api/v1/staff-shifts/{$shift->id}/end")
            ->assertOk();

        $this->assertDatabaseHas('staff_shifts', [
            'id' => $shift->id,
            'ended_by_user_id' => $manager->id,
        ]);
    }

    public function test_ended_shift_is_preserved_as_historical_row(): void
    {
        [$organization, , $restaurant] = $this->createTenant();
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');
        $shift = $this->startShift($restaurant, $waiter, $waiter);

        $this->actingAs($waiter, 'web')
            ->postJson("/api/v1/staff-shifts/{$shift->id}/end")
            ->assertOk();

        $this->assertDatabaseHas('staff_shifts', ['id' => $shift->id]);
        $this->assertDatabaseCount('staff_shifts', 1);
    }

    public function test_ending_an_already_ended_shift_returns_conflict(): void
    {
        [$organization, , $restaurant] = $this->createTenant();
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');
        $shift = $this->startShift($restaurant, $waiter, $waiter);
        $this->endShift($shift, $waiter);

        $this->actingAs($waiter, 'web')
            ->postJson("/api/v1/staff-shifts/{$shift->id}/end")
            ->assertStatus(409);
    }
}
