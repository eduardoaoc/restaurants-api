<?php

namespace Tests\Feature\TableSession;

use App\Models\TableSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithOrders;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class PaymentIdempotencyTest extends TestCase
{
    use InteractsWithOrders, InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    /**
     * @return array{0: TableSession, 1: User}
     */
    private function sessionWithBillableOrder(float $price = 100.0): array
    {
        [, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $rp->update(['price' => $price]);
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);
        $this->createWaiterOrder($table, $owner, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);

        return [$session, $owner];
    }

    public function test_first_request_creates_and_returns_201(): void
    {
        [$session, $owner] = $this->sessionWithBillableOrder();

        $this->actingAs($owner, 'web')
            ->postJson(
                "/api/v1/table-sessions/{$session->id}/payments",
                ['method' => 'cash', 'amount' => '40.00'],
                ['Idempotency-Key' => 'pay-1']
            )
            ->assertStatus(201);

        $this->assertDatabaseCount('payment_records', 1);
    }

    public function test_same_key_replays_the_same_payment_with_200(): void
    {
        [$session, $owner] = $this->sessionWithBillableOrder();
        $payload = ['method' => 'cash', 'amount' => '40.00'];
        $headers = ['Idempotency-Key' => 'pay-1'];

        $first = $this->actingAs($owner, 'web')
            ->postJson("/api/v1/table-sessions/{$session->id}/payments", $payload, $headers)
            ->assertStatus(201);

        $second = $this->actingAs($owner, 'web')
            ->postJson("/api/v1/table-sessions/{$session->id}/payments", $payload, $headers)
            ->assertStatus(200);

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertDatabaseCount('payment_records', 1);
    }

    public function test_same_key_with_different_payload_returns_409(): void
    {
        [$session, $owner] = $this->sessionWithBillableOrder();
        $headers = ['Idempotency-Key' => 'pay-1'];

        $this->actingAs($owner, 'web')
            ->postJson("/api/v1/table-sessions/{$session->id}/payments", ['method' => 'cash', 'amount' => '40.00'], $headers)
            ->assertStatus(201);

        $response = $this->actingAs($owner, 'web')
            ->postJson("/api/v1/table-sessions/{$session->id}/payments", ['method' => 'card', 'amount' => '40.00'], $headers)
            ->assertStatus(409);

        $response->assertJson(['error' => ['code' => 'PAYMENT_IDEMPOTENCY_KEY_REUSED']]);
        $this->assertDatabaseCount('payment_records', 1);
    }

    public function test_different_key_creates_a_second_payment(): void
    {
        [$session, $owner] = $this->sessionWithBillableOrder();

        $this->actingAs($owner, 'web')
            ->postJson("/api/v1/table-sessions/{$session->id}/payments", ['method' => 'cash', 'amount' => '40.00'], ['Idempotency-Key' => 'key-1'])
            ->assertStatus(201);

        $this->actingAs($owner, 'web')
            ->postJson("/api/v1/table-sessions/{$session->id}/payments", ['method' => 'cash', 'amount' => '60.00'], ['Idempotency-Key' => 'key-2'])
            ->assertStatus(201);

        $this->assertDatabaseCount('payment_records', 2);
    }

    public function test_no_key_never_deduplicates(): void
    {
        [$session, $owner] = $this->sessionWithBillableOrder(1000.0);

        $this->actingAs($owner, 'web')
            ->postJson("/api/v1/table-sessions/{$session->id}/payments", ['method' => 'cash', 'amount' => '40.00'])
            ->assertStatus(201);

        $this->actingAs($owner, 'web')
            ->postJson("/api/v1/table-sessions/{$session->id}/payments", ['method' => 'cash', 'amount' => '40.00'])
            ->assertStatus(201);

        $this->assertDatabaseCount('payment_records', 2);
    }

    public function test_oversized_idempotency_key_is_rejected(): void
    {
        [$session, $owner] = $this->sessionWithBillableOrder();

        $this->actingAs($owner, 'web')
            ->postJson(
                "/api/v1/table-sessions/{$session->id}/payments",
                ['method' => 'cash', 'amount' => '40.00'],
                ['Idempotency-Key' => str_repeat('a', 101)]
            )
            ->assertStatus(422);
    }

    /**
     * The critical case (Bloco 13 report / section 78): the payment that
     * completes the bill and flips payment_status to paid must still
     * replay successfully on retry with the same key — a naive
     * "already-paid" check running before the idempotency check would
     * wrongly turn this into a 409.
     */
    public function test_final_payment_replay_after_session_becomes_paid_returns_200_not_already_paid(): void
    {
        [$session, $owner] = $this->sessionWithBillableOrder(50.0);
        $payload = ['method' => 'card', 'amount' => '50.00'];
        $headers = ['Idempotency-Key' => 'final-payment'];

        $this->actingAs($owner, 'web')
            ->postJson("/api/v1/table-sessions/{$session->id}/payments", $payload, $headers)
            ->assertStatus(201);

        $session->refresh();
        $this->assertSame('paid', $session->payment_status);

        // Simulated network retry of the exact same request.
        $retry = $this->actingAs($owner, 'web')
            ->postJson("/api/v1/table-sessions/{$session->id}/payments", $payload, $headers)
            ->assertStatus(200);

        $retry->assertJson(['data' => ['amount' => '50.00']]);
        $this->assertDatabaseCount('payment_records', 1);
    }

    public function test_new_payment_after_paid_with_different_key_returns_already_paid(): void
    {
        [$session, $owner] = $this->sessionWithBillableOrder(50.0);

        $this->actingAs($owner, 'web')
            ->postJson(
                "/api/v1/table-sessions/{$session->id}/payments",
                ['method' => 'card', 'amount' => '50.00'],
                ['Idempotency-Key' => 'first']
            )
            ->assertStatus(201);

        $this->actingAs($owner, 'web')
            ->postJson(
                "/api/v1/table-sessions/{$session->id}/payments",
                ['method' => 'cash', 'amount' => '1.00'],
                ['Idempotency-Key' => 'different-key']
            )
            ->assertStatus(409)
            ->assertJson(['error' => ['code' => 'TABLE_SESSION_ALREADY_PAID']]);
    }

    public function test_key_is_scoped_per_table_session_not_globally(): void
    {
        [, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $tableA = $this->createTable($restaurant);
        $sessionA = $this->openSession($tableA, $owner);
        $this->createWaiterOrder($tableA, $owner, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);

        $headers = ['Idempotency-Key' => 'same-key'];

        $this->actingAs($owner, 'web')
            ->postJson("/api/v1/table-sessions/{$sessionA->id}/payments", ['method' => 'cash', 'amount' => '10.00'], $headers)
            ->assertStatus(201);

        $tableB = $this->createTable($restaurant);
        $sessionB = $this->openSession($tableB, $owner);
        $this->createWaiterOrder($tableB, $owner, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);

        $this->actingAs($owner, 'web')
            ->postJson("/api/v1/table-sessions/{$sessionB->id}/payments", ['method' => 'cash', 'amount' => '10.00'], $headers)
            ->assertStatus(201);

        $this->assertDatabaseCount('payment_records', 2);
    }
}
