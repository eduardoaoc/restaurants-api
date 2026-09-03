<?php

namespace Tests\Feature\RestaurantSettings;

use App\Models\Order;
use App\Models\PrintRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithOrders;
use Tests\Concerns\InteractsWithPayments;
use Tests\Concerns\InteractsWithTableRequests;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * customer_ordering_enabled / customer_order_requires_approval /
 * waiter_call_enabled / bill_request_enabled / *_printing_enabled — the
 * operational feature toggles introduced by RestaurantSettings.
 */
class FeatureTogglesTest extends TestCase
{
    use InteractsWithOrders, InteractsWithPayments, InteractsWithTableRequests, InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    // --- customer_order_requires_approval defaults ------------------------

    public function test_default_public_order_is_auto_confirmed_and_appears_in_the_kds_queue(): void
    {
        [, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);

        $response = $this->postJson("/api/v1/public/tables/{$table->public_token}/orders", [
            'items' => [['restaurant_product_id' => $rp->id, 'quantity' => 1]],
        ])->assertStatus(201);

        $this->assertSame('confirmed', $response->json('data.status'));

        $order = Order::query()->firstOrFail();
        $this->assertSame(Order::STATUS_CONFIRMED, $order->status);
        $this->assertNull($order->approved_by_user_id);
        $this->assertNull($order->approved_at);

        $kds = $this->actingAs($owner, 'web')->getJson('/api/v1/kitchen/orders')->assertOk();
        $this->assertTrue(collect($kds->json('data.orders'))->pluck('id')->contains($order->id));
    }

    public function test_controlled_mode_still_requires_waiter_approval(): void
    {
        [, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $this->requireOrderApproval($restaurant);
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);

        $this->postJson("/api/v1/public/tables/{$table->public_token}/orders", [
            'items' => [['restaurant_product_id' => $rp->id, 'quantity' => 1]],
        ])->assertStatus(201);

        $order = Order::query()->firstOrFail();
        $this->assertSame(Order::STATUS_WAITING_APPROVAL, $order->status);

        $this->actingAs($owner, 'web')
            ->postJson("/api/v1/orders/{$order->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.order.status', 'confirmed');
    }

    public function test_replay_of_an_idempotent_order_is_unaffected_by_a_later_requires_approval_change(): void
    {
        [, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);

        $payload = ['items' => [['restaurant_product_id' => $rp->id, 'quantity' => 1]]];
        $headers = ['Idempotency-Key' => 'replay-key'];

        $first = $this->postJson("/api/v1/public/tables/{$table->public_token}/orders", $payload, $headers)
            ->assertStatus(201);

        $this->requireOrderApproval($restaurant);

        $replay = $this->postJson("/api/v1/public/tables/{$table->public_token}/orders", $payload, $headers)
            ->assertStatus(200);

        $this->assertSame($first->json('data.id'), $replay->json('data.id'));
        $this->assertSame('confirmed', $replay->json('data.status'));
        $this->assertSame(1, Order::query()->count());
    }

    // --- customer_ordering_enabled ------------------------------------

    public function test_customer_ordering_disabled_blocks_public_order_but_not_the_menu(): void
    {
        [, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $restaurant->settings()->update(['customer_ordering_enabled' => false]);
        $this->createMenu($restaurant);
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);

        $this->getJson("/api/v1/public/tables/{$table->public_token}/menu")->assertOk();

        $this->postJson("/api/v1/public/tables/{$table->public_token}/orders", [
            'items' => [['restaurant_product_id' => $rp->id, 'quantity' => 1]],
        ])
            ->assertStatus(409)
            ->assertJson(['error' => ['code' => 'CUSTOMER_ORDERING_DISABLED']]);

        $this->assertSame(0, Order::query()->count());
    }

    // --- waiter_call_enabled / bill_request_enabled --------------------

    public function test_waiter_call_disabled_returns_409(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $restaurant->settings()->update(['waiter_call_enabled' => false]);
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);

        $this->postJson("/api/v1/public/tables/{$table->public_token}/requests/call-waiter")
            ->assertStatus(409)
            ->assertJson(['error' => ['code' => 'WAITER_CALL_DISABLED']]);
    }

    public function test_bill_request_disabled_returns_409(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $restaurant->settings()->update(['bill_request_enabled' => false]);
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);

        $this->postJson("/api/v1/public/tables/{$table->public_token}/requests/bill")
            ->assertStatus(409)
            ->assertJson(['error' => ['code' => 'BILL_REQUEST_DISABLED']]);
    }

    public function test_disabled_feature_takes_precedence_but_missing_session_still_reports_correctly_when_enabled(): void
    {
        [, , $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        // Feature is enabled (default) and there is no active session:
        // the original 409 TABLE_SESSION_NOT_ACTIVE contract must survive.
        $this->postJson("/api/v1/public/tables/{$table->public_token}/requests/call-waiter")
            ->assertStatus(409)
            ->assertJson(['error' => ['code' => 'TABLE_SESSION_NOT_ACTIVE']]);
    }

    // --- Printing toggles ------------------------------------------------

    public function test_kitchen_ticket_printing_disabled_blocks_print_but_not_preview(): void
    {
        [, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $restaurant->settings()->update(['kitchen_ticket_printing_enabled' => false]);
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);
        $order = $this->createWaiterOrder($table, $owner, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);

        $this->actingAs($owner, 'web')
            ->getJson("/api/v1/orders/{$order->id}/kitchen-ticket")
            ->assertOk();

        $this->actingAs($owner, 'web')
            ->postJson("/api/v1/orders/{$order->id}/kitchen-ticket/print")
            ->assertStatus(409)
            ->assertJson(['error' => ['code' => 'KITCHEN_TICKET_PRINTING_DISABLED']]);

        $this->assertSame(0, PrintRecord::query()->count());
    }

    public function test_bill_receipt_printing_disabled_blocks_print_but_not_preview(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $restaurant->settings()->update(['bill_receipt_printing_enabled' => false]);
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);

        $this->actingAs($owner, 'web')
            ->getJson("/api/v1/table-sessions/{$session->id}/receipt")
            ->assertOk();

        $this->actingAs($owner, 'web')
            ->postJson("/api/v1/table-sessions/{$session->id}/receipt/print")
            ->assertStatus(409)
            ->assertJson(['error' => ['code' => 'BILL_RECEIPT_PRINTING_DISABLED']]);

        $this->assertSame(0, PrintRecord::query()->count());
    }
}
