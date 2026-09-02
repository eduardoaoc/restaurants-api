<?php

namespace Tests\Feature\Public;

use App\Actions\Orders\ApproveOrderAction;
use App\Actions\Tables\CloseTableAction;
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

    public function test_key_is_scoped_per_table_session_not_globally(): void
    {
        [, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
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
}
