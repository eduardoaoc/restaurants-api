<?php

namespace Tests\Feature\Order;

use App\Actions\Orders\RejectOrderAction;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithOrders;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Passo 3.6 audit: proves GET /api/v1/orders/{order} already carries
 * everything the "Mesa -> Ver pedidos -> #1812" detail screen needs — no
 * new endpoint required. See OrderResource/OrderItemResource/
 * OrderItemModifierResource for the contract this locks in, and
 * OrderLifecycleTest::test_order_keeps_its_snapshots_after_the_catalog_changes
 * for the pre-existing snapshot-immutability proof this complements.
 */
class OrderDetailContractTest extends TestCase
{
    use InteractsWithOrders, InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
    }

    public function test_complex_order_detail_returns_the_full_contract_with_correct_math(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();

        $burgerProduct = $this->createProduct($organization, null, [['locale' => 'es', 'name' => 'Hamburguesa Clásica', 'description' => 'Carne, queso y salsa']]);
        $burger = $this->createRestaurantProduct($restaurant, $burgerProduct, 12.90);

        $drinkProduct = $this->createProduct($organization, null, [['locale' => 'es', 'name' => 'Refresco']]);
        $drink = $this->createRestaurantProduct($restaurant, $drinkProduct, 3.50);
        $sizeGroup = $this->createModifierGroup($drink, null, 0, 1, false, [['locale' => 'es', 'name' => 'Tamaño']]);
        $large = $this->createModifierOption($sizeGroup, null, 1.00, [['locale' => 'es', 'name' => 'Grande']]);

        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);

        $order = $this->createWaiterOrder($table, $owner, [
            ['restaurant_product_id' => $burger->id, 'quantity' => 2, 'note' => 'Sin cebolla'],
            ['restaurant_product_id' => $drink->id, 'quantity' => 1, 'modifier_option_ids' => [$large->id]],
        ], ['note' => 'Mesa junto a la ventana']);

        $response = $this->actingAs($owner, 'web')
            ->getJson("/api/v1/orders/{$order->id}")
            ->assertOk();

        $data = $response->json('data.order');

        // --- Order-level fields -------------------------------------------
        $this->assertSame($order->id, $data['id']);
        $this->assertSame('#'.$order->id, $data['order_number']);
        $this->assertSame('waiter', $data['origin']);
        $this->assertSame('confirmed', $data['status']);
        $this->assertSame($restaurant->id, $data['restaurant']['id']);
        $this->assertSame($table->id, $data['table']['id']);
        $this->assertSame('Mesa junto a la ventana', $data['customer_note']);
        $this->assertNotEmpty($data['created_at']);
        $this->assertCount(2, $data['items']);

        // --- Item 1: Hamburguesa x2, no modifier, own note -----------------
        $burgerItem = $data['items'][0];
        $this->assertSame('Hamburguesa Clásica', $burgerItem['name']);
        $this->assertSame('Carne, queso y salsa', $burgerItem['description']);
        $this->assertSame('12.90', $burgerItem['unit_price']);
        $this->assertSame(2, $burgerItem['quantity']);
        $this->assertSame('Sin cebolla', $burgerItem['note']);
        $this->assertSame('0.00', $burgerItem['modifiers_unit_total']);
        $this->assertSame('12.90', $burgerItem['unit_total']);
        $this->assertSame('25.80', $burgerItem['line_total']);
        $this->assertSame([], $burgerItem['modifiers']);

        // --- Item 2: Refresco x1, with a modifier --------------------------
        $drinkItem = $data['items'][1];
        $this->assertSame('Refresco', $drinkItem['name']);
        $this->assertSame('3.50', $drinkItem['unit_price']);
        $this->assertSame(1, $drinkItem['quantity']);
        $this->assertNull($drinkItem['note']);
        $this->assertSame('1.00', $drinkItem['modifiers_unit_total']);
        $this->assertSame('4.50', $drinkItem['unit_total']);
        $this->assertSame('4.50', $drinkItem['line_total']);
        $this->assertCount(1, $drinkItem['modifiers']);
        $this->assertSame('Tamaño', $drinkItem['modifiers'][0]['group_name']);
        $this->assertSame('Grande', $drinkItem['modifiers'][0]['name']);
        $this->assertSame('1.00', $drinkItem['modifiers'][0]['price_delta']);

        // --- Order totals: subtotal/modifiers_total/total match the items --
        // subtotal = 12.90*2 + 3.50*1 = 29.30; modifiers_total = 0*2 + 1.00*1 = 1.00
        // total = 30.30, which must also equal the sum of both line_totals.
        $this->assertSame('29.30', $data['subtotal']);
        $this->assertSame('1.00', $data['modifiers_total']);
        $this->assertSame('30.30', $data['total']);
        $this->assertSame(
            $data['total'],
            bcadd($burgerItem['line_total'], $drinkItem['line_total'], 2),
        );
    }

    public function test_order_detail_is_available_and_unhidden_across_every_lifecycle_status(): void
    {
        [, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);

        $statuses = [
            Order::STATUS_CONFIRMED => fn () => $this->createWaiterOrder($table, $owner, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]),
            Order::STATUS_ACCEPTED => fn () => $this->advanceOrderTo($this->createWaiterOrder($table, $owner, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]), Order::STATUS_ACCEPTED, $owner),
            Order::STATUS_PREPARING => fn () => $this->advanceOrderTo($this->createWaiterOrder($table, $owner, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]), Order::STATUS_PREPARING, $owner),
            Order::STATUS_READY => fn () => $this->advanceOrderTo($this->createWaiterOrder($table, $owner, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]), Order::STATUS_READY, $owner),
            Order::STATUS_SERVED => fn () => $this->advanceOrderTo($this->createWaiterOrder($table, $owner, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]), Order::STATUS_SERVED, $owner),
            Order::STATUS_CANCELLED => function () use ($table, $restaurant, $owner, $rp) {
                $this->requireOrderApproval($restaurant);
                $order = $this->createCustomerOrder($table, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);

                return app(RejectOrderAction::class)->execute($order, $owner);
            },
            Order::STATUS_WAITING_APPROVAL => function () use ($table, $restaurant, $rp) {
                $this->requireOrderApproval($restaurant);

                return $this->createCustomerOrder($table, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);
            },
        ];

        foreach ($statuses as $expectedStatus => $build) {
            $order = $build();

            $response = $this->actingAs($owner, 'web')
                ->getJson("/api/v1/orders/{$order->id}")
                ->assertOk();

            $this->assertSame($expectedStatus, $response->json('data.order.status'), "status={$expectedStatus}");
            $this->assertNotEmpty($response->json('data.order.items'), "items missing for status={$expectedStatus}");
            $this->assertSame('10.00', $response->json('data.order.items.0.unit_price'), "unit_price hidden for status={$expectedStatus}");
        }
    }

    public function test_order_detail_query_count_does_not_grow_with_item_count(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);

        $singleItemOrder = $this->createWaiterOrder($table, $owner, [
            $this->itemWithModifier($organization, $restaurant),
        ]);

        $fiveItemOrder = $this->createWaiterOrder($table, $owner, [
            $this->itemWithModifier($organization, $restaurant),
            $this->itemWithModifier($organization, $restaurant),
            $this->itemWithModifier($organization, $restaurant),
            $this->itemWithModifier($organization, $restaurant),
            $this->itemWithModifier($organization, $restaurant),
        ]);

        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->actingAs($owner, 'web')->getJson("/api/v1/orders/{$singleItemOrder->id}")->assertOk();
        $singleItemQueries = count(DB::getQueryLog());

        DB::flushQueryLog();
        $this->actingAs($owner, 'web')->getJson("/api/v1/orders/{$fiveItemOrder->id}")->assertOk();
        $fiveItemQueries = count(DB::getQueryLog());
        DB::disableQueryLog();

        // items.modifiers is eager-loaded as one query each (see
        // OrderController::show), so the query count must stay flat
        // regardless of how many items/modifiers the order has — a
        // regression to N+1 would make $fiveItemQueries grow with the
        // item count instead.
        $this->assertSame($singleItemQueries, $fiveItemQueries);
    }

    /**
     * @return array{restaurant_product_id: int, quantity: int, modifier_option_ids: array<int, int>}
     */
    private function itemWithModifier(mixed $organization, mixed $restaurant): array
    {
        $product = $this->createProduct($organization);
        $rp = $this->createRestaurantProduct($restaurant, $product, 5.0);
        $group = $this->createModifierGroup($rp, null, 0, 1, false);
        $option = $this->createModifierOption($group, null, 0.50);

        return ['restaurant_product_id' => $rp->id, 'quantity' => 1, 'modifier_option_ids' => [$option->id]];
    }
}
