<?php

namespace Tests\Feature\WaiterCall;

use App\Actions\Tables\AssignWaiterAction;
use App\Actions\Tables\CallResponsibleWaiterAction;
use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithOrders;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bloco 4 — who may call the responsible waiter (reuses assign_waiters):
 * owner/manager, never waiter/kitchen/cashier. Acknowledge: the called
 * waiter themself, or assign_waiters.
 */
class AuthorizationTest extends TestCase
{
    use InteractsWithOrders, InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    public function test_waiter_without_assign_waiters_permission_cannot_call(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);
        app(AssignWaiterAction::class)->execute($session, $waiter, $owner);

        $this->actingAs($waiter, 'web')
            ->postJson("/api/v1/table-sessions/{$session->id}/waiter-calls")
            ->assertForbidden();

        $this->assertDatabaseCount('waiter_calls', 0);
    }

    public function test_kitchen_cannot_call(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $kitchen = $this->createStaff($organization, $restaurant, 'kitchen', 'K-1');
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);
        app(AssignWaiterAction::class)->execute($session, $waiter, $owner);

        $this->actingAs($kitchen, 'web')
            ->postJson("/api/v1/table-sessions/{$session->id}/waiter-calls")
            ->assertForbidden();
    }

    public function test_manager_outside_restaurant_scope_gets_not_found(): void
    {
        [$organization, $owner, $restaurantA] = $this->createTenant();
        $restaurantB = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $managerB = $this->createStaff($organization, $restaurantB, 'manager', 'M-B');
        $waiterA = $this->createStaff($organization, $restaurantA, 'waiter', 'W-A');
        $table = $this->createTable($restaurantA);
        $session = $this->openSession($table, $owner);
        app(AssignWaiterAction::class)->execute($session, $waiterA, $owner);

        $this->actingAs($managerB, 'web')
            ->postJson("/api/v1/table-sessions/{$session->id}/waiter-calls")
            ->assertNotFound();
    }

    public function test_cross_tenant_owner_gets_not_found(): void
    {
        [, $ownerA, $restaurantA] = $this->createTenant();
        $table = $this->createTable($restaurantA);
        $session = $this->openSession($table, $ownerA);

        [, $ownerB] = $this->createTenant();

        $this->actingAs($ownerB, 'web')
            ->postJson("/api/v1/table-sessions/{$session->id}/waiter-calls")
            ->assertNotFound();
    }

    public function test_guest_is_unauthenticated(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);

        $this->postJson("/api/v1/table-sessions/{$session->id}/waiter-calls")->assertUnauthorized();
    }

    public function test_staff_cannot_acknowledge_another_waiters_call(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');
        $otherWaiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-2');
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);
        app(AssignWaiterAction::class)->execute($session, $waiter, $owner);
        $call = app(CallResponsibleWaiterAction::class)->execute($session, $owner);

        $this->actingAs($otherWaiter, 'web')
            ->postJson("/api/v1/waiter-calls/{$call->id}/acknowledge")
            ->assertForbidden();
    }

    public function test_manager_with_permission_can_acknowledge_on_behalf_of_the_waiter(): void
    {
        [$organization, , $restaurant] = $this->createTenant();
        $manager = $this->createStaff($organization, $restaurant, 'manager', 'M-1');
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $manager);
        app(AssignWaiterAction::class)->execute($session, $waiter, $manager);

        $response = $this->actingAs($manager, 'web')
            ->postJson("/api/v1/table-sessions/{$session->id}/waiter-calls")
            ->assertCreated();
        $callId = $response->json('data.waiter_call.id');

        $this->actingAs($manager, 'web')
            ->postJson("/api/v1/waiter-calls/{$callId}/acknowledge")
            ->assertOk();
    }
}
