<?php

namespace Tests\Feature\Public;

use App\Actions\Orders\ApproveOrderAction;
use App\Actions\Tables\CloseTableAction;
use App\Models\AuditLog;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithOrders;
use Tests\Concerns\InteractsWithPayments;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class PublicOrderIdempotencyTest extends TestCase
{
    use InteractsWithOrders, InteractsWithPayments, InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
    }

    public function test_first_request_creates_and_returns_201(): void
    {
        [, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);

        $this->postJson(
            "/api/v1/public/tables/{$table->public_token}/orders",
            ['items' => [['restaurant_product_id' => $rp->id, 'quantity' => 1]]],
            ['Idempotency-Key' => 'abc123']
        )->assertStatus(201);

        $this->assertDatabaseCount('orders', 1);
    }

    public function test_same_key_replays_the_same_order_with_200(): void
    {
        [, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);

        $payload = ['items' => [['restaurant_product_id' => $rp->id, 'quantity' => 1]]];
        $headers = ['Idempotency-Key' => 'abc123'];

        $first = $this->postJson("/api/v1/public/tables/{$table->public_token}/orders", $payload, $headers)
            ->assertStatus(201);

        $second = $this->postJson("/api/v1/public/tables/{$table->public_token}/orders", $payload, $headers)
            ->assertStatus(200);

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_same_key_with_different_payload_returns_409(): void
    {
        [, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);

        $headers = ['Idempotency-Key' => 'abc123'];

        $this->postJson(
            "/api/v1/public/tables/{$table->public_token}/orders",
            ['items' => [['restaurant_product_id' => $rp->id, 'quantity' => 1]]],
            $headers
        )->assertStatus(201);

        $response = $this->postJson(
            "/api/v1/public/tables/{$table->public_token}/orders",
            ['items' => [['restaurant_product_id' => $rp->id, 'quantity' => 2]]],
            $headers
        )->assertStatus(409);

        $response->assertJson(['error' => ['code' => 'IDEMPOTENCY_KEY_REUSED']]);
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_different_key_creates_a_second_order(): void
    {
        [, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);

        $payload = ['items' => [['restaurant_product_id' => $rp->id, 'quantity' => 1]]];

        $this->postJson("/api/v1/public/tables/{$table->public_token}/orders", $payload, ['Idempotency-Key' => 'key-1'])
            ->assertStatus(201);
        $this->postJson("/api/v1/public/tables/{$table->public_token}/orders", $payload, ['Idempotency-Key' => 'key-2'])
            ->assertStatus(201);

        $this->assertDatabaseCount('orders', 2);
    }

    public function test_no_key_never_deduplicates(): void
    {
        [, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);

        $payload = ['items' => [['restaurant_product_id' => $rp->id, 'quantity' => 1]]];

        $this->postJson("/api/v1/public/tables/{$table->public_token}/orders", $payload)->assertStatus(201);
        $this->postJson("/api/v1/public/tables/{$table->public_token}/orders", $payload)->assertStatus(201);

        $this->assertDatabaseCount('orders', 2);
    }

    public function test_oversized_idempotency_key_is_rejected(): void
    {
        [, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);

        $this->postJson(
            "/api/v1/public/tables/{$table->public_token}/orders",
            ['items' => [['restaurant_product_id' => $rp->id, 'quantity' => 1]]],
            ['Idempotency-Key' => str_repeat('a', 101)]
        )->assertStatus(422);
    }

    /**
     * A key is looked up scoped to the Table (see
     * CreatePublicOrderAction::findReplayCandidate() — this is what makes
     * a closed-session replay recoverable, see the tests below), but a
     * NEW session at the same table must not inherit an old session's
     * key: since a different session is now active than the one the
     * matched order belongs to, this is a fresh operation, not a replay.
     */
    public function test_key_is_scoped_per_table_session_not_globally(): void
    {
        [, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $this->requireOrderApproval($restaurant);
        $table = $this->createTable($restaurant);
        $session1 = $this->openSession($table, $owner);

        $payload = ['items' => [['restaurant_product_id' => $rp->id, 'quantity' => 1]]];
        $headers = ['Idempotency-Key' => 'same-key'];

        $this->postJson("/api/v1/public/tables/{$table->public_token}/orders", $payload, $headers)
            ->assertStatus(201);

        // Make the order in session1 servable/paid so the session can
        // close (Bloco 13 requires it) without creating a second order,
        // which would break the orders-count assertion below.
        $orderInSession1 = Order::query()->latest('id')->firstOrFail();
        $orderInSession1 = app(ApproveOrderAction::class)->execute($orderInSession1, $owner);
        $orderInSession1 = $this->advanceOrderTo($orderInSession1, Order::STATUS_SERVED, $owner);
        $this->recordPayment($session1, $owner, $orderInSession1->total);
        app(CloseTableAction::class)->execute($session1, $owner);

        $this->openSession($table, $owner);

        // Same idempotency key, but a brand new session: must create a new order.
        $this->postJson("/api/v1/public/tables/{$table->public_token}/orders", $payload, $headers)
            ->assertStatus(201);

        $this->assertDatabaseCount('orders', 2);
        $this->assertSame(2, Order::query()->count());
    }

    // --- Replay stability across a later RestaurantSettings change ------

    public function test_replay_after_ordering_disabled_returns_the_original_order(): void
    {
        [, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);

        $payload = ['items' => [['restaurant_product_id' => $rp->id, 'quantity' => 1]]];
        $headers = ['Idempotency-Key' => 'ord-1'];

        $first = $this->postJson("/api/v1/public/tables/{$table->public_token}/orders", $payload, $headers)
            ->assertStatus(201);

        $restaurant->settings()->update(['customer_ordering_enabled' => false]);

        $replay = $this->postJson("/api/v1/public/tables/{$table->public_token}/orders", $payload, $headers)
            ->assertStatus(200);

        $this->assertSame($first->json('data.id'), $replay->json('data.id'));
        $this->assertSame(1, Order::query()->count());
        $this->assertSame(1, AuditLog::query()->where('event', AuditLog::EVENT_ORDER_CREATED)->count());
    }

    public function test_ordering_disabled_then_new_key_is_rejected(): void
    {
        [, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);

        $payload = ['items' => [['restaurant_product_id' => $rp->id, 'quantity' => 1]]];

        $this->postJson("/api/v1/public/tables/{$table->public_token}/orders", $payload, ['Idempotency-Key' => 'ord-1'])
            ->assertStatus(201);

        $restaurant->settings()->update(['customer_ordering_enabled' => false]);

        $this->postJson("/api/v1/public/tables/{$table->public_token}/orders", $payload, ['Idempotency-Key' => 'brand-new-key'])
            ->assertStatus(409)
            ->assertJson(['error' => ['code' => 'CUSTOMER_ORDERING_DISABLED']]);

        $this->assertSame(1, Order::query()->count());
    }

    public function test_replay_after_requires_approval_turned_on_keeps_original_confirmed_status(): void
    {
        [, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);

        $payload = ['items' => [['restaurant_product_id' => $rp->id, 'quantity' => 1]]];
        $headers = ['Idempotency-Key' => 'ord-2'];

        // Default: requires_approval = false -> confirmed.
        $this->postJson("/api/v1/public/tables/{$table->public_token}/orders", $payload, $headers)
            ->assertStatus(201)
            ->assertJsonPath('data.status', 'confirmed');

        $this->requireOrderApproval($restaurant);

        $this->postJson("/api/v1/public/tables/{$table->public_token}/orders", $payload, $headers)
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'confirmed');

        $this->assertSame(1, Order::query()->count());
    }

    public function test_replay_after_requires_approval_turned_off_keeps_original_waiting_approval_status(): void
    {
        [, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $this->requireOrderApproval($restaurant);
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);

        $payload = ['items' => [['restaurant_product_id' => $rp->id, 'quantity' => 1]]];
        $headers = ['Idempotency-Key' => 'ord-3'];

        $this->postJson("/api/v1/public/tables/{$table->public_token}/orders", $payload, $headers)
            ->assertStatus(201)
            ->assertJsonPath('data.status', 'waiting_approval');

        $restaurant->settings()->update(['customer_order_requires_approval' => false]);

        $this->postJson("/api/v1/public/tables/{$table->public_token}/orders", $payload, $headers)
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'waiting_approval');

        $this->assertSame(1, Order::query()->count());
    }

    // --- Replay after the original session has closed -------------------

    /**
     * A retry of the exact same key/payload arrives after the table's
     * session (that produced the original order) has already closed, and
     * no new session has been opened since. This is recovery of an
     * existing result, not a new operation — it must return the original
     * order rather than 409 TABLE_SESSION_NOT_ACTIVE. See
     * CreatePublicOrderAction::findReplayCandidate(): with no currently
     * active session, the payload-hash-verified match is trusted as the
     * same original operation.
     */
    public function test_replay_after_the_session_closed_returns_the_original_order(): void
    {
        [, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);

        $payload = ['items' => [['restaurant_product_id' => $rp->id, 'quantity' => 1]]];
        $headers = ['Idempotency-Key' => 'ord-4'];

        $first = $this->postJson("/api/v1/public/tables/{$table->public_token}/orders", $payload, $headers)
            ->assertStatus(201);

        $order = Order::query()->latest('id')->firstOrFail();
        $order = $this->advanceOrderTo($order, Order::STATUS_SERVED, $owner);
        $this->recordPayment($session, $owner, $order->total);
        app(CloseTableAction::class)->execute($session, $owner);

        $replay = $this->postJson("/api/v1/public/tables/{$table->public_token}/orders", $payload, $headers)
            ->assertStatus(200);

        $this->assertSame($first->json('data.id'), $replay->json('data.id'));
        $this->assertSame(1, Order::query()->count());
    }

    public function test_payload_mismatch_still_conflicts_even_across_a_settings_change(): void
    {
        [, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);
        $headers = ['Idempotency-Key' => 'ord-5'];

        $this->postJson(
            "/api/v1/public/tables/{$table->public_token}/orders",
            ['items' => [['restaurant_product_id' => $rp->id, 'quantity' => 1]]],
            $headers
        )->assertStatus(201);

        $restaurant->settings()->update(['customer_order_requires_approval' => true]);

        $this->postJson(
            "/api/v1/public/tables/{$table->public_token}/orders",
            ['items' => [['restaurant_product_id' => $rp->id, 'quantity' => 3]]],
            $headers
        )
            ->assertStatus(409)
            ->assertJson(['error' => ['code' => 'IDEMPOTENCY_KEY_REUSED']]);

        $this->assertSame(1, Order::query()->count());
    }
}
