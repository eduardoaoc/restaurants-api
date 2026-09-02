<?php

namespace Tests\Feature\Printing;

use App\Actions\Orders\RejectOrderAction;
use App\Actions\Tables\CloseTableAction;
use App\Models\Order;
use App\Models\PrintRecord;
use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithOrders;
use Tests\Concerns\InteractsWithPayments;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class BillReceiptTest extends TestCase
{
    use InteractsWithOrders, InteractsWithPayments, InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    public function test_content_type_is_json(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);

        $this->actingAs($owner, 'web')
            ->getJson("/api/v1/table-sessions/{$session->id}/receipt")
            ->assertHeader('Content-Type', 'application/json');
    }

    // --- Payment states -------------------------------------------

    public function test_receipt_works_before_payment(): void
    {
        [, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $rp->update(['price' => 50.0]);
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);
        $this->createWaiterOrder($table, $owner, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);

        $response = $this->actingAs($owner, 'web')
            ->getJson("/api/v1/table-sessions/{$session->id}/receipt")
            ->assertOk();

        $response->assertJson([
            'data' => [
                'orders_total' => '50.00',
                'paid_total' => '0.00',
                'balance' => '50.00',
                'payment_status' => 'unpaid',
            ],
        ]);
        $this->assertSame([], $response->json('data.payments'));
    }

    public function test_receipt_reflects_partial_payment(): void
    {
        [, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $rp->update(['price' => 50.0]);
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);
        $this->createWaiterOrder($table, $owner, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);
        $this->recordPayment($session, $owner, '20.00');

        $response = $this->actingAs($owner, 'web')
            ->getJson("/api/v1/table-sessions/{$session->id}/receipt")
            ->assertOk();

        $response->assertJson([
            'data' => ['orders_total' => '50.00', 'paid_total' => '20.00', 'balance' => '30.00', 'payment_status' => 'unpaid'],
        ]);
    }

    public function test_receipt_reflects_full_payment(): void
    {
        [, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $rp->update(['price' => 50.0]);
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);
        $this->createWaiterOrder($table, $owner, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);
        $this->recordPayment($session, $owner, '50.00');

        $response = $this->actingAs($owner, 'web')
            ->getJson("/api/v1/table-sessions/{$session->id}/receipt")
            ->assertOk();

        $response->assertJson([
            'data' => ['orders_total' => '50.00', 'paid_total' => '50.00', 'balance' => '0.00', 'payment_status' => 'paid'],
        ]);
        $this->assertCount(1, $response->json('data.payments'));
    }

    public function test_receipt_available_after_session_closed(): void
    {
        [, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);
        $order = $this->createWaiterOrder($table, $owner, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);
        $this->advanceOrderTo($order, Order::STATUS_SERVED, $owner);
        $this->recordPayment($session, $owner, $order->total);
        app(CloseTableAction::class)->execute($session, $owner);

        $this->actingAs($owner, 'web')
            ->getJson("/api/v1/table-sessions/{$session->id}/receipt")
            ->assertOk()
            ->assertJson(['data' => ['payment_status' => 'paid']]);
    }

    // --- Billable orders --------------------------------------------

    public function test_receipt_includes_served_and_preparing_excludes_cancelled_and_waiting_approval(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $kitchen = $this->createStaff($organization, $restaurant, 'kitchen', 'K-1');
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);

        $productServed = $this->createProduct($organization);
        $rpServed = $this->createRestaurantProduct($restaurant, $productServed, 20.0);
        $served = $this->createWaiterOrder($table, $owner, [['restaurant_product_id' => $rpServed->id, 'quantity' => 1]]);
        $this->advanceOrderTo($served, Order::STATUS_SERVED, $kitchen);

        $productPreparing = $this->createProduct($organization);
        $rpPreparing = $this->createRestaurantProduct($restaurant, $productPreparing, 15.0);
        $preparing = $this->createWaiterOrder($table, $owner, [['restaurant_product_id' => $rpPreparing->id, 'quantity' => 1]]);
        $this->advanceOrderTo($preparing, Order::STATUS_PREPARING, $kitchen);

        $productCancelled = $this->createProduct($organization);
        $rpCancelled = $this->createRestaurantProduct($restaurant, $productCancelled, 99.0);
        $cancelled = $this->createCustomerOrder($table, [['restaurant_product_id' => $rpCancelled->id, 'quantity' => 1]]);
        app(RejectOrderAction::class)->execute($cancelled, $owner);

        $productWaiting = $this->createProduct($organization);
        $rpWaiting = $this->createRestaurantProduct($restaurant, $productWaiting, 77.0);
        $this->createCustomerOrder($table, [['restaurant_product_id' => $rpWaiting->id, 'quantity' => 1]]);

        $response = $this->actingAs($owner, 'web')
            ->getJson("/api/v1/table-sessions/{$session->id}/receipt")
            ->assertOk();

        $orderIds = collect($response->json('data.orders'))->pluck('id');
        $this->assertTrue($orderIds->contains($served->id));
        $this->assertTrue($orderIds->contains($preparing->id));
        $this->assertFalse($orderIds->contains($cancelled->id));
        $this->assertCount(2, $orderIds);
        $this->assertSame('35.00', $response->json('data.orders_total'));
    }

    // --- Items / modifiers -------------------------------------------

    public function test_receipt_order_includes_items_and_modifiers(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $product = $this->createProduct($organization, null, [['locale' => 'es', 'name' => 'Hamburguesa Clásica']]);
        $rp = $this->createRestaurantProduct($restaurant, $product, 10.0);
        $group = $this->createModifierGroup($rp, null, 0, 1, false);
        $option = $this->createModifierOption($group, null, 1.5, [['locale' => 'es', 'name' => 'Bacon']]);

        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);
        $order = $this->createWaiterOrder($table, $owner, [
            ['restaurant_product_id' => $rp->id, 'quantity' => 2, 'modifier_option_ids' => [$option->id]],
        ]);

        $response = $this->actingAs($owner, 'web')
            ->getJson("/api/v1/table-sessions/{$session->id}/receipt")
            ->assertOk();

        $orderJson = $response->json('data.orders.0');
        $this->assertSame($order->id, $orderJson['id']);
        $this->assertSame((string) $order->total, $orderJson['total']);
        $this->assertCount(1, $orderJson['items']);

        $itemJson = $orderJson['items'][0];
        $this->assertSame('Hamburguesa Clásica', $itemJson['name']);
        $this->assertSame(2, $itemJson['quantity']);
        $this->assertSame('10.00', $itemJson['unit_price']);
        $this->assertCount(1, $itemJson['modifiers']);
        $this->assertSame(['name' => 'Bacon', 'price_delta' => '1.50'], $itemJson['modifiers'][0]);
        $this->assertSame('23.00', $itemJson['line_total']);
    }

    // --- Totals via SessionBillCalculator ----------------------------

    public function test_receipt_totals_match_the_bill_endpoint_exactly(): void
    {
        [, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $rp->update(['price' => 33.30]);
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);
        $this->createWaiterOrder($table, $owner, [['restaurant_product_id' => $rp->id, 'quantity' => 3]]);
        $this->recordPayment($session, $owner, '40.00');

        $bill = $this->actingAs($owner, 'web')->getJson("/api/v1/table-sessions/{$session->id}/bill")->assertOk();
        $receipt = $this->actingAs($owner, 'web')->getJson("/api/v1/table-sessions/{$session->id}/receipt")->assertOk();

        $this->assertSame($bill->json('data.orders_total'), $receipt->json('data.orders_total'));
        $this->assertSame($bill->json('data.paid_total'), $receipt->json('data.paid_total'));
        $this->assertSame($bill->json('data.balance'), $receipt->json('data.balance'));
    }

    // --- Snapshots -----------------------------------------------

    public function test_receipt_uses_snapshots_not_live_catalog_data(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $product = $this->createProduct($organization, null, [['locale' => 'es', 'name' => 'Nombre Original']]);
        $rp = $this->createRestaurantProduct($restaurant, $product, 10.0);
        $group = $this->createModifierGroup($rp, null, 0, 1, false);
        $option = $this->createModifierOption($group, null, 1.0, [['locale' => 'es', 'name' => 'Opcion Original']]);

        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);
        $this->createWaiterOrder($table, $owner, [
            ['restaurant_product_id' => $rp->id, 'quantity' => 1, 'modifier_option_ids' => [$option->id]],
        ]);

        $product->translations()->update(['name' => 'Nombre Nuevo']);
        $option->translations()->update(['name' => 'Opcion Nueva']);
        $option->update(['price_delta' => 999.0]);
        $rp->update(['price' => 999.0]);

        $response = $this->actingAs($owner, 'web')
            ->getJson("/api/v1/table-sessions/{$session->id}/receipt")
            ->assertOk();

        $item = $response->json('data.orders.0.items.0');
        $this->assertSame('Nombre Original', $item['name']);
        $this->assertSame('10.00', $item['unit_price']);
        $this->assertSame('Opcion Original', $item['modifiers'][0]['name']);
        $this->assertSame('1.00', $item['modifiers'][0]['price_delta']);
    }

    // --- Monetary contract ------------------------------------------

    public function test_monetary_fields_are_strings(): void
    {
        [, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);
        $this->createWaiterOrder($table, $owner, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);

        $response = $this->actingAs($owner, 'web')
            ->getJson("/api/v1/table-sessions/{$session->id}/receipt")
            ->assertOk();

        $this->assertIsString($response->json('data.orders_total'));
        $this->assertIsString($response->json('data.paid_total'));
        $this->assertIsString($response->json('data.balance'));
        $this->assertIsString($response->json('data.orders.0.total'));
    }

    // --- Resource contract ------------------------------------------

    public function test_receipt_resource_contract(): void
    {
        [, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $table = $this->createTable($restaurant, 'Mesa 12', 12);
        $session = $this->openSession($table, $owner);
        $this->createWaiterOrder($table, $owner, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);

        $response = $this->actingAs($owner, 'web')
            ->getJson("/api/v1/table-sessions/{$session->id}/receipt")
            ->assertOk();

        $json = $response->json('data');
        $this->assertEqualsCanonicalizing([
            'document_type', 'restaurant', 'table', 'table_session_id', 'opened_at', 'closed_at',
            'orders', 'orders_total', 'paid_total', 'balance', 'payment_status', 'payments', 'generated_at',
        ], array_keys($json));
        $this->assertSame('bill_receipt', $json['document_type']);
        $this->assertEqualsCanonicalizing(['id', 'name'], array_keys($json['restaurant']));
        $this->assertEqualsCanonicalizing(['id', 'name', 'number'], array_keys($json['table']));
        $this->assertSame($session->id, $json['table_session_id']);
    }

    // --- Preview vs print -------------------------------------------

    public function test_preview_does_not_create_a_print_record(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);

        $this->actingAs($owner, 'web')->getJson("/api/v1/table-sessions/{$session->id}/receipt")->assertOk();

        $this->assertDatabaseCount('print_records', 0);
    }

    public function test_print_creates_a_print_record_with_correct_context(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);

        $response = $this->actingAs($owner, 'web')
            ->postJson("/api/v1/table-sessions/{$session->id}/receipt/print")
            ->assertStatus(201);

        $printRecordId = $response->json('data.print_record_id');
        $this->assertNotNull($printRecordId);
        $this->assertSame('bill_receipt', $response->json('data.document.document_type'));

        $printRecord = PrintRecord::query()->findOrFail($printRecordId);
        $this->assertSame(PrintRecord::DOCUMENT_TYPE_BILL_RECEIPT, $printRecord->document_type);
        $this->assertSame($organization->id, $printRecord->organization_id);
        $this->assertSame($restaurant->id, $printRecord->restaurant_id);
        $this->assertNull($printRecord->order_id);
        $this->assertSame($session->id, $printRecord->table_session_id);
        $this->assertSame($owner->id, $printRecord->requested_by_user_id);
    }

    public function test_reprint_creates_another_print_record(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);

        $this->actingAs($owner, 'web')->postJson("/api/v1/table-sessions/{$session->id}/receipt/print")->assertStatus(201);
        $this->actingAs($owner, 'web')->postJson("/api/v1/table-sessions/{$session->id}/receipt/print")->assertStatus(201);

        $this->assertDatabaseCount('print_records', 2);
    }

    public function test_closed_session_receipt_can_be_printed(): void
    {
        [, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);
        $order = $this->createWaiterOrder($table, $owner, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);
        $this->advanceOrderTo($order, Order::STATUS_SERVED, $owner);
        $this->recordPayment($session, $owner, $order->total);
        app(CloseTableAction::class)->execute($session, $owner);

        $this->actingAs($owner, 'web')
            ->postJson("/api/v1/table-sessions/{$session->id}/receipt/print")
            ->assertStatus(201);
    }

    // --- Tenant / restaurant isolation ------------------------------

    public function test_other_organization_session_returns_404(): void
    {
        [, $ownerA] = $this->createTenant();
        [, $ownerB, $restaurantB] = $this->createTenant();
        $tableB = $this->createTable($restaurantB);
        $sessionB = $this->openSession($tableB, $ownerB);

        $this->actingAs($ownerA, 'web')
            ->getJson("/api/v1/table-sessions/{$sessionB->id}/receipt")
            ->assertStatus(404);
    }

    public function test_other_restaurant_same_organization_session_returns_404(): void
    {
        [$organization, $owner, $restaurantA] = $this->createTenant();
        $waiterA = $this->createStaff($organization, $restaurantA, 'waiter', 'W-A');
        $restaurantB = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $tableB = $this->createTable($restaurantB);
        $sessionB = $this->openSession($tableB, $owner);

        $this->actingAs($waiterA, 'web')
            ->getJson("/api/v1/table-sessions/{$sessionB->id}/receipt")
            ->assertStatus(404);
    }

    // --- Scope vs permission ------------------------------------------

    public function test_kitchen_cannot_view_receipt(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $kitchen = $this->createStaff($organization, $restaurant, 'kitchen', 'K-1');
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);

        $this->actingAs($kitchen, 'web')
            ->getJson("/api/v1/table-sessions/{$session->id}/receipt")
            ->assertStatus(403);
    }

    // --- Permission by role -------------------------------------------

    public function test_waiter_can_view_and_print_receipt(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);

        $this->actingAs($waiter, 'web')->getJson("/api/v1/table-sessions/{$session->id}/receipt")->assertOk();
        $this->actingAs($waiter, 'web')->postJson("/api/v1/table-sessions/{$session->id}/receipt/print")->assertStatus(201);
    }

    public function test_cashier_can_view_and_print_receipt(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $cashier = $this->createStaff($organization, $restaurant, 'cashier', 'C-1');
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);

        $this->actingAs($cashier, 'web')->getJson("/api/v1/table-sessions/{$session->id}/receipt")->assertOk();
        $this->actingAs($cashier, 'web')->postJson("/api/v1/table-sessions/{$session->id}/receipt/print")->assertStatus(201);
    }

    public function test_owner_can_print_receipt_in_any_restaurant_of_their_organization(): void
    {
        [$organization, $owner, $restaurantA] = $this->createTenant();
        $tableA = $this->createTable($restaurantA);
        $sessionA = $this->openSession($tableA, $owner);

        $restaurantB = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $tableB = $this->createTable($restaurantB);
        $sessionB = $this->openSession($tableB, $owner);

        $this->actingAs($owner, 'web')->getJson("/api/v1/table-sessions/{$sessionA->id}/receipt")->assertOk();
        $this->actingAs($owner, 'web')->getJson("/api/v1/table-sessions/{$sessionB->id}/receipt")->assertOk();
    }
}
