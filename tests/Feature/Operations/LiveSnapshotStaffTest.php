<?php

namespace Tests\Feature\Operations;

use App\Actions\Tables\AssignWaiterAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithOrders;
use Tests\Concerns\InteractsWithPayments;
use Tests\Concerns\InteractsWithStaffShifts;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bloco 5 — Operations Live: active staff list and load, derived
 * exclusively from StaffShift + assigned_waiter_user_id, never persisted.
 */
class LiveSnapshotStaffTest extends TestCase
{
    use InteractsWithOrders, InteractsWithPayments, InteractsWithStaffShifts, InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    public function test_staff_with_active_shift_appears_and_ended_shift_does_not(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $activeWaiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1', name: 'Mateo');
        $endedWaiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-2', name: 'Lucía');

        $this->startShift($restaurant, $activeWaiter, $activeWaiter);
        $endedShift = $this->startShift($restaurant, $endedWaiter, $endedWaiter);
        $this->endShift($endedShift, $endedWaiter);

        $response = $this->actingAs($owner, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/operations/live")
            ->assertOk();

        $names = collect($response->json('data.staff'))->pluck('user.name')->all();
        $this->assertContains('Mateo', $names);
        $this->assertNotContains('Lucía', $names);
        $this->assertSame(1, $response->json('data.summary.staff.active'));
    }

    public function test_waiter_load_counts_assigned_tables_and_guests_from_active_sessions_only(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');
        $this->startShift($restaurant, $waiter, $waiter);

        $tableA = $this->createTable($restaurant);
        $tableB = $this->createTable($restaurant);
        $tableC = $this->createTable($restaurant);

        $sessionA = $this->openSession($tableA, $owner, 3);
        $sessionB = $this->openSession($tableB, $owner, 4);
        $sessionC = $this->openSession($tableC, $owner, 9);

        app(AssignWaiterAction::class)->execute($sessionA, $waiter, $owner);
        app(AssignWaiterAction::class)->execute($sessionB, $waiter, $owner);
        // sessionC is assigned then closed — must not count.
        app(AssignWaiterAction::class)->execute($sessionC, $waiter, $owner);
        $this->closeSessionWithFullPayment($sessionC, $owner);

        $response = $this->actingAs($owner, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/operations/live")
            ->assertOk();

        $staffEntry = collect($response->json('data.staff'))->firstWhere('user.id', $waiter->id);

        $this->assertSame(2, $staffEntry['load']['assigned_tables']);
        $this->assertSame(7, $staffEntry['load']['assigned_guests']);
    }

    public function test_waiter_off_shift_but_still_assigned_keeps_assignment_and_is_flagged(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);
        app(AssignWaiterAction::class)->execute($session, $waiter, $owner);
        // Waiter never started (or already ended) their shift — assignment
        // must be preserved regardless (Bloco 2 untouched).

        $response = $this->actingAs($owner, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/operations/live")
            ->assertOk();

        $this->assertSame($waiter->id, $session->fresh()->assigned_waiter_user_id);

        $tableView = collect($response->json('data.unassigned_tables'))->firstWhere('id', $table->id);
        $this->assertContains('assigned_waiter_off_shift', $tableView['flags']);
        $this->assertSame($waiter->id, $tableView['session']['assigned_waiter']['id']);

        $alert = collect($response->json('data.alerts'))->firstWhere('type', 'assigned_waiter_off_shift');
        $this->assertNotNull($alert);
        $this->assertSame('warning', $alert['severity']);
        $this->assertSame($waiter->id, $alert['user_id']);
    }

    public function test_staff_member_with_no_role_assignment_pending_attention_is_zero_by_default(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $kitchen = $this->createStaff($organization, $restaurant, 'kitchen', 'K-1');
        $this->startShift($restaurant, $kitchen, $kitchen);

        $response = $this->actingAs($owner, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/operations/live")
            ->assertOk();

        $staffEntry = collect($response->json('data.staff'))->firstWhere('user.id', $kitchen->id);
        $this->assertSame('kitchen', $staffEntry['role']);
        $this->assertSame(0, $staffEntry['load']['assigned_tables']);
        $this->assertSame(0, $staffEntry['load']['pending_attention']);
    }
}
