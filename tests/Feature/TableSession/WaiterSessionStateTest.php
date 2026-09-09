<?php

namespace Tests\Feature\TableSession;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithOrders;
use Tests\Concerns\InteractsWithPayments;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bloco 2 — waiter assignment is only valid against an active TableSession.
 */
class WaiterSessionStateTest extends TestCase
{
    use InteractsWithOrders, InteractsWithPayments, InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    public function test_assigning_a_waiter_to_a_closed_session_returns_conflict(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');

        $session = $this->closeSessionWithFullPayment($session, $owner);

        $this->actingAs($owner, 'web')
            ->putJson("/api/v1/table-sessions/{$session->id}/waiter", ['user_id' => $waiter->id])
            ->assertStatus(409);

        $this->assertNull($session->fresh()->assigned_waiter_user_id);
    }

    public function test_unassigning_a_waiter_from_a_closed_session_returns_conflict(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');

        $this->actingAs($owner, 'web')
            ->putJson("/api/v1/table-sessions/{$session->id}/waiter", ['user_id' => $waiter->id])
            ->assertOk();

        $session = $this->closeSessionWithFullPayment($session->fresh(), $owner);

        $this->actingAs($owner, 'web')
            ->deleteJson("/api/v1/table-sessions/{$session->id}/waiter")
            ->assertStatus(409);

        $this->assertSame($waiter->id, $session->fresh()->assigned_waiter_user_id);
    }

    public function test_active_session_allows_assignment(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');

        $this->actingAs($owner, 'web')
            ->putJson("/api/v1/table-sessions/{$session->id}/waiter", ['user_id' => $waiter->id])
            ->assertOk();
    }
}
