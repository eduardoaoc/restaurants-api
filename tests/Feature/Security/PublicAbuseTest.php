<?php

namespace Tests\Feature\Security;

use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithOrders;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class PublicAbuseTest extends TestCase
{
    use InteractsWithOrders, InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRolesAndPermissions();
    }

    public static function invalidTokens(): array
    {
        return array_map(fn ($token) => [$token], ['invalid', str_repeat('z', 48), 'truncated', str_repeat('z', 10000), '%27%20OR%201=1%20--', '1', '%252e%252e%252f']);
    }

    #[DataProvider('invalidTokens')]
    public function test_invalid_tokens_do_not_disclose_tenant_or_admin_data(string $token): void
    {
        foreach (["/public/tables/{$token}", "/public/tables/{$token}/menu", "/public/feedback/{$token}", "/public/visits/{$token}"] as $url) {
            $response = $this->getJson('/api/v1'.$url)->assertNotFound();
            $response->assertJsonMissingPath('data');
        }
        $this->getJson('/api/v1/tables/resolve/'.$token)->assertUnauthorized();
    }

    public static function invalidQuantities(): array
    {
        return array_map(fn ($quantity) => [$quantity], [-1, 0, 51, PHP_INT_MAX, 1.5, ['x'], str_repeat('9', 10000)]);
    }

    #[DataProvider('invalidQuantities')]
    public function test_invalid_quantities_cannot_create_orders(mixed $quantity): void
    {
        [, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);
        $this->postJson("/api/v1/public/tables/{$table->public_token}/orders", ['items' => [['restaurant_product_id' => $rp->id, 'quantity' => $quantity]]])->assertUnprocessable();
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_foreign_disabled_unknown_products_and_modifiers_are_rejected(): void
    {
        [, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        [, , , $foreign] = $this->createTenantWithRestaurantProduct();
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);
        $url = "/api/v1/public/tables/{$table->public_token}/orders";
        foreach ([999999, $foreign->id] as $id) {
            $this->postJson($url, ['items' => [['restaurant_product_id' => $id, 'quantity' => 1]]])->assertUnprocessable();
        }
        $foreignOption = $this->createModifierOption($this->createModifierGroup($foreign));
        foreach ([999999, $foreignOption->id] as $id) {
            $this->postJson($url, ['items' => [['restaurant_product_id' => $rp->id, 'quantity' => 1, 'modifier_option_ids' => [$id]]]])->assertUnprocessable();
        }
        $rp->update(['available' => false]);
        $this->postJson($url, ['items' => [['restaurant_product_id' => $rp->id, 'quantity' => 1]]])->assertUnprocessable();
        $rp->update(['available' => true]);
        $rp->product->update(['status' => 'inactive']);
        $this->postJson($url, ['items' => [['restaurant_product_id' => $rp->id, 'quantity' => 1]]])->assertUnprocessable();
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_order_metadata_and_price_are_authoritative_and_replay_is_single_effect(): void
    {
        [, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);
        $payload = ['price' => '0.01', 'total' => '0.01', 'status' => 'served', 'restaurant_id' => 999999, 'session_id' => 999999, 'table_session_id' => 999999, 'created_by' => $owner->id,
            'items' => [['restaurant_product_id' => $rp->id, 'quantity' => 2, 'price' => '0.01', 'unit_price_snapshot' => '0.01']]];
        $url = "/api/v1/public/tables/{$table->public_token}/orders";
        $first = $this->postJson($url, $payload, ['Idempotency-Key' => 'same'])->assertCreated();
        $this->postJson($url, $payload, ['Idempotency-Key' => 'same'])->assertOk()->assertJsonPath('data.order.id', $first->json('data.order.id'));
        $order = Order::query()->sole();
        $this->assertSame('20.00', $order->total);
        $this->assertSame(Order::STATUS_CONFIRMED, $order->status);
        $this->assertSame($session->id, $order->table_session_id);
        $this->assertSame($restaurant->id, $order->restaurant_id);
        $this->assertNull($order->created_by_user_id);
        $this->assertSame('10.00', $order->items()->sole()->unit_price_snapshot);
    }

    public function test_qr_case_mutation_disabled_table_and_inactive_restaurant(): void
    {
        [, , $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $this->assertSame(48, strlen($table->public_token));
        $url = '/api/v1/public/tables/';
        $this->getJson($url.$table->public_token)->assertOk();
        $this->getJson($url.strtoupper($table->public_token))->assertNotFound();
        $this->getJson($url.$table->public_token)->assertOk();
        $table->update(['status' => 'inactive']);
        $this->getJson($url.$table->public_token)->assertNotFound();
        $table->update(['status' => 'active']);
        $restaurant->update(['status' => 'inactive']);
        $this->getJson($url.$table->public_token)->assertNotFound();
    }
}
