<?php

namespace Tests\Feature\TableSession;

use App\Actions\Tables\CloseTableAction;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithOrders;
use Tests\Concerns\InteractsWithPayments;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bloco 4 — after a successful transfer, the existing flows (new orders,
 * closing the session, filtering orders by session) must keep working
 * unchanged on the same session.
 */
class TransferPostConditionsTest extends TestCase
{
    use InteractsWithOrders, InteractsWithPayments, InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    public function test_new_orders_can_be_placed_on_the_target_table_after_transfer(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $tableA = $this->createTable($restaurant);
        $tableB = $this->createTable($restaurant);
        $session = $this->openSession($tableA, $owner);
        $restaurantProduct = $this->createRestaurantProduct($restaurant, $this->createProduct($organization), 10.0);

        $this->actingAs($owner, 'web')
            ->postJson("/api/v1/table-sessions/{$session->id}/transfer", ['target_table_id' => $tableB->id])
            ->assertOk();

        $this->actingAs($owner, 'web')
            ->postJson("/api/v1/tables/{$tableB->id}/orders", [
                'items' => [['restaurant_product_id' => $restaurantProduct->id, 'quantity' => 1]],
            ])
            ->assertCreated()
            ->assertJsonPath('data.order.table.id', $tableB->id);

        $newOrder = Order::query()->latest('id')->first();
        $this->assertSame($session->id, $newOrder->table_session_id);
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_closing_the_session_after_transfer_works_normally(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $tableA = $this->createTable($restaurant);
        $tableB = $this->createTable($restaurant);
        $session = $this->openSession($tableA, $owner);

        $this->actingAs($owner, 'web')
            ->postJson("/api/v1/table-sessions/{$session->id}/transfer", ['target_table_id' => $tableB->id])
            ->assertOk();

        $restaurantProduct = $this->createRestaurantProduct($restaurant, $this->createProduct($organization), 25.0);
        $order = $this->createWaiterOrder($tableB, $owner, [
            ['restaurant_product_id' => $restaurantProduct->id, 'quantity' => 1],
        ]);
        $order = $this->advanceOrderTo($order, Order::STATUS_SERVED, $owner);
        $this->recordPayment($session, $owner, $order->total);

        $this->actingAs($owner, 'web')
            ->postJson("/api/v1/tables/{$tableB->id}/close")
            ->assertOk()
            ->assertJsonPath('data.session.status', 'closed')
            ->assertJsonPath('data.session.id', $session->id);

        $this->assertSame('closed', $session->fresh()->status);
        $this->assertDatabaseCount('table_sessions', 1);
    }

    public function test_orders_index_filtered_by_table_session_id_returns_only_that_sessions_orders(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $restaurantProduct = $this->createRestaurantProduct($restaurant, $this->createProduct($organization), 10.0);

        $firstSession = $this->openSession($table, $owner);
        $firstOrder = $this->createWaiterOrder($table, $owner, [
            ['restaurant_product_id' => $restaurantProduct->id, 'quantity' => 1],
        ]);
        $firstOrder = $this->advanceOrderTo($firstOrder, Order::STATUS_SERVED, $owner);
        $this->recordPayment($firstSession, $owner, $firstOrder->total);
        app(CloseTableAction::class)->execute($firstSession, $owner);

        // $table's `activeSession` relation was cached (as the now-closed
        // first session) by createWaiterOrder()'s lazy access above —
        // refresh so the next open/order pair sees the real current state,
        // exactly as a fresh request would.
        $table->refresh();

        $secondSession = $this->openSession($table, $owner);
        $table->refresh();
        $secondOrder = $this->createWaiterOrder($table, $owner, [
            ['restaurant_product_id' => $restaurantProduct->id, 'quantity' => 1],
        ]);

        $response = $this->actingAs($owner, 'web')
            ->getJson("/api/v1/orders?table_session_id={$secondSession->id}")
            ->assertOk();

        $ids = collect($response->json('data.orders'))->pluck('id')->all();
        $this->assertSame([$secondOrder->id], $ids);
        $this->assertNotContains($firstOrder->id, $ids);
    }
}
