<?php

namespace Tests\Feature\Table;

use App\Actions\Tables\OpenTableAction;
use App\Models\Order;
use App\Models\TableSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\Concerns\InteractsWithOrders;
use Tests\Concerns\InteractsWithPayments;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class TableSessionHistoryTest extends TestCase
{
    use InteractsWithOrders, InteractsWithPayments, InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    /**
     * Bloco 13 requires a session to have a fully-paid, served bill before
     * it can close — see CloseTableAction. Build exactly that.
     */
    private function makeSessionCloseable(TableSession $session, User $owner): void
    {
        $restaurantProduct = $this->createRestaurantProduct($session->restaurant, $this->createProduct($session->restaurant->organization), 10.0);
        $order = $this->createWaiterOrder($session->table, $owner, [
            ['restaurant_product_id' => $restaurantProduct->id, 'quantity' => 1],
        ]);
        $order = $this->advanceOrderTo($order, Order::STATUS_SERVED, $owner);
        $this->recordPayment($session, $owner, $order->total);
    }

    public function test_reopening_a_table_preserves_history_as_two_sessions(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);

        $this->actingAs($owner, 'web')
            ->postJson("/api/v1/tables/{$table->id}/open", ['guest_count' => 2])
            ->assertCreated();

        $firstSession = $table->activeSession()->firstOrFail();
        $this->makeSessionCloseable($firstSession, $owner);

        $this->actingAs($owner, 'web')
            ->postJson("/api/v1/tables/{$table->id}/close")
            ->assertOk();

        $this->actingAs($owner, 'web')
            ->postJson("/api/v1/tables/{$table->id}/open", ['guest_count' => 5])
            ->assertCreated();

        $this->assertDatabaseCount('table_sessions', 2);

        $sessions = TableSession::query()->where('table_id', $table->id)->orderBy('id')->get();

        $this->assertSame('closed', $sessions[0]->status);
        $this->assertSame(2, $sessions[0]->guest_count);

        $this->assertSame('occupied', $sessions[1]->status);
        $this->assertSame(5, $sessions[1]->guest_count);
    }

    public function test_closing_never_deletes_the_session_row(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $session = app(OpenTableAction::class)->execute($table, $owner, 4);
        $this->makeSessionCloseable($session, $owner);

        $this->actingAs($owner, 'web')
            ->postJson("/api/v1/tables/{$table->id}/close")
            ->assertOk();

        $this->assertDatabaseHas('table_sessions', ['id' => $session->id]);
    }

    public function test_a_table_session_must_belong_to_the_same_restaurant_as_its_table(): void
    {
        [, , $restaurantA] = $this->createTenant();
        [, $ownerB, $restaurantB] = $this->createTenant();
        $tableA = $this->createTable($restaurantA);

        $this->expectException(InvalidArgumentException::class);

        TableSession::query()->create([
            'restaurant_id' => $restaurantB->id,
            'table_id' => $tableA->id,
            'opened_by_user_id' => $ownerB->id,
            'guest_count' => 2,
            'status' => 'occupied',
            'opened_at' => now(),
        ]);
    }
}
