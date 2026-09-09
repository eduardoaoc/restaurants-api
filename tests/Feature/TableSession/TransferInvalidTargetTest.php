<?php

namespace Tests\Feature\TableSession;

use App\Actions\Tables\CloseTableAction;
use App\Models\Order;
use App\Models\Restaurant;
use App\Models\TableSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithOrders;
use Tests\Concerns\InteractsWithPayments;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bloco 4 — invalid transfer targets: occupied, same table as origin,
 * cross-restaurant (even within the same organization), closed session,
 * nonexistent target.
 */
class TransferInvalidTargetTest extends TestCase
{
    use InteractsWithOrders, InteractsWithPayments, InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    public function test_target_table_with_active_session_returns_conflict(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $tableA = $this->createTable($restaurant);
        $tableB = $this->createTable($restaurant);
        $sessionA = $this->openSession($tableA, $owner);
        $sessionB = $this->openSession($tableB, $owner);

        $this->actingAs($owner, 'web')
            ->postJson("/api/v1/table-sessions/{$sessionA->id}/transfer", ['target_table_id' => $tableB->id])
            ->assertStatus(409);

        $this->assertSame($tableA->id, $sessionA->fresh()->table_id);
        $this->assertSame($tableB->id, $sessionB->fresh()->table_id);
        $this->assertDatabaseCount('table_sessions', 2);
    }

    public function test_transferring_to_the_same_table_is_rejected(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);

        $this->actingAs($owner, 'web')
            ->postJson("/api/v1/table-sessions/{$session->id}/transfer", ['target_table_id' => $table->id])
            ->assertStatus(409);

        $this->assertSame($table->id, $session->fresh()->table_id);
    }

    public function test_target_from_a_different_restaurant_of_the_same_organization_is_not_found(): void
    {
        [$organization, $owner, $restaurantA] = $this->createTenant();
        $restaurantB = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $tableA = $this->createTable($restaurantA);
        $tableB = $this->createTable($restaurantB);
        $session = $this->openSession($tableA, $owner);

        $this->actingAs($owner, 'web')
            ->postJson("/api/v1/table-sessions/{$session->id}/transfer", ['target_table_id' => $tableB->id])
            ->assertNotFound();

        $this->assertSame($tableA->id, $session->fresh()->table_id);
    }

    public function test_nonexistent_target_returns_validation_error(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);

        $this->actingAs($owner, 'web')
            ->postJson("/api/v1/table-sessions/{$session->id}/transfer", ['target_table_id' => 999999])
            ->assertStatus(422);
    }

    public function test_missing_target_returns_validation_error(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);

        $this->actingAs($owner, 'web')
            ->postJson("/api/v1/table-sessions/{$session->id}/transfer", [])
            ->assertStatus(422);
    }

    public function test_closed_session_cannot_be_transferred(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $tableA = $this->createTable($restaurant);
        $tableB = $this->createTable($restaurant);
        $session = $this->openSession($tableA, $owner);

        $restaurantProduct = $this->createRestaurantProduct($restaurant, $this->createProduct($organization), 10.0);
        $order = $this->createWaiterOrder($tableA, $owner, [
            ['restaurant_product_id' => $restaurantProduct->id, 'quantity' => 1],
        ]);
        $order = $this->advanceOrderTo($order, Order::STATUS_SERVED, $owner);
        $this->recordPayment($session, $owner, $order->total);
        $session = $this->closeSessionAsIs($session, $owner);

        $this->actingAs($owner, 'web')
            ->postJson("/api/v1/table-sessions/{$session->id}/transfer", ['target_table_id' => $tableB->id])
            ->assertStatus(409);
    }

    private function closeSessionAsIs(TableSession $session, User $actor): TableSession
    {
        return app(CloseTableAction::class)->execute($session, $actor);
    }

    public function test_source_table_becomes_free_only_after_a_successful_transfer(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $tableA = $this->createTable($restaurant);
        $tableB = $this->createTable($restaurant);
        $tableC = $this->createTable($restaurant);
        $sessionAtoBTarget = $this->openSession($tableA, $owner);
        $this->openSession($tableB, $owner);

        // B is occupied, so this attempt must fail and leave A's
        // occupancy untouched — atomicity check (no partial state).
        $this->actingAs($owner, 'web')
            ->postJson("/api/v1/table-sessions/{$sessionAtoBTarget->id}/transfer", ['target_table_id' => $tableB->id])
            ->assertStatus(409);

        $this->assertNotNull($tableA->activeSession()->first());
        $this->assertNull($tableC->activeSession()->first());
    }
}
