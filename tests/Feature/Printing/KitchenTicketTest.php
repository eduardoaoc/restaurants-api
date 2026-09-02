<?php

namespace Tests\Feature\Printing;

use App\Actions\Orders\RejectOrderAction;
use App\Models\Order;
use App\Models\PrintRecord;
use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithOrders;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class KitchenTicketTest extends TestCase
{
    use InteractsWithOrders, InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    // --- Printable statuses ------------------------------------------

    /**
     * @return array<string, array{0: string}>
     */
    public static function printableStatuses(): array
    {
        return [
            'confirmed' => [Order::STATUS_CONFIRMED],
            'accepted' => [Order::STATUS_ACCEPTED],
            'preparing' => [Order::STATUS_PREPARING],
            'ready' => [Order::STATUS_READY],
            'served' => [Order::STATUS_SERVED],
        ];
    }

    #[DataProvider('printableStatuses')]
    public function test_order_is_printable_at_status(string $status): void
    {
        [, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);
        $order = $this->createWaiterOrder($table, $owner, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);

        if ($status !== Order::STATUS_CONFIRMED) {
            $order = $this->advanceOrderTo($order, $status, $owner);
        }

        $this->actingAs($owner, 'web')
            ->getJson("/api/v1/orders/{$order->id}/kitchen-ticket")
            ->assertOk()
            ->assertJson(['data' => ['document_type' => 'kitchen_ticket', 'order' => ['id' => $order->id, 'status' => $status]]]);
    }

    // --- Non-printable statuses -------------------------------------

    public function test_waiting_approval_order_is_not_printable(): void
    {
        [, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);
        $order = $this->createCustomerOrder($table, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);

        $response = $this->actingAs($owner, 'web')
            ->getJson("/api/v1/orders/{$order->id}/kitchen-ticket")
            ->assertStatus(409);

        $response->assertJson(['error' => ['code' => 'ORDER_NOT_PRINTABLE']]);
    }

    public function test_cancelled_order_is_not_printable(): void
    {
        [, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);
        $order = $this->createCustomerOrder($table, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);
        app(RejectOrderAction::class)->execute($order, $owner);

        $this->actingAs($owner, 'web')
            ->getJson("/api/v1/orders/{$order->id}/kitchen-ticket")
            ->assertStatus(409)
            ->assertJson(['error' => ['code' => 'ORDER_NOT_PRINTABLE']]);
    }

    public function test_not_printable_applies_equally_to_print_endpoint(): void
    {
        [, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);
        $order = $this->createCustomerOrder($table, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);

        $this->actingAs($owner, 'web')
            ->postJson("/api/v1/orders/{$order->id}/kitchen-ticket/print")
            ->assertStatus(409)
            ->assertJson(['error' => ['code' => 'ORDER_NOT_PRINTABLE']]);

        $this->assertDatabaseCount('print_records', 0);
    }

    // --- Snapshots -----------------------------------------------

    public function test_kitchen_ticket_uses_snapshots_not_live_catalog_data(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $product = $this->createProduct($organization, null, [['locale' => 'es', 'name' => 'Nombre Original']]);
        $rp = $this->createRestaurantProduct($restaurant, $product, 10.0);
        $group = $this->createModifierGroup($rp, null, 0, 1, false, [['locale' => 'es', 'name' => 'Grupo Original']]);
        $option = $this->createModifierOption($group, null, 1.0, [['locale' => 'es', 'name' => 'Opcion Original']]);

        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);
        $order = $this->createWaiterOrder($table, $owner, [
            ['restaurant_product_id' => $rp->id, 'quantity' => 1, 'modifier_option_ids' => [$option->id]],
        ]);

        $product->translations()->update(['name' => 'Nombre Nuevo']);
        $group->translations()->update(['name' => 'Grupo Nuevo']);
        $option->translations()->update(['name' => 'Opcion Nueva']);
        $rp->update(['price' => 999.0]);

        $response = $this->actingAs($owner, 'web')
            ->getJson("/api/v1/orders/{$order->id}/kitchen-ticket")
            ->assertOk();

        $item = $response->json('data.items.0');
        $this->assertSame('Nombre Original', $item['name']);
        $this->assertSame('Grupo Original', $item['modifiers'][0]['group_name']);
        $this->assertSame('Opcion Original', $item['modifiers'][0]['name']);
    }

    // --- No financial data -----------------------------------------

    public function test_no_financial_fields_in_kitchen_ticket(): void
    {
        [, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $group = $this->createModifierGroup($rp, null, 0, 1, false);
        $this->createModifierOption($group, null, 1.5);
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);
        $order = $this->createWaiterOrder($table, $owner, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);

        $response = $this->actingAs($owner, 'web')
            ->getJson("/api/v1/orders/{$order->id}/kitchen-ticket")
            ->assertOk();

        $json = json_encode($response->json());
        foreach (['unit_price', 'price_delta', 'subtotal', '"total"', 'payment'] as $field) {
            $this->assertStringNotContainsString($field, $json);
        }
    }

    // --- Resource contract ---------------------------------------

    public function test_kitchen_ticket_resource_contract(): void
    {
        [, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $table = $this->createTable($restaurant, 'Mesa 12', 12);
        $this->openSession($table, $owner);
        $order = $this->createWaiterOrder($table, $owner, [
            ['restaurant_product_id' => $rp->id, 'quantity' => 2, 'note' => 'Sin cebolla'],
        ], ['note' => 'Order note']);

        $response = $this->actingAs($owner, 'web')
            ->getJson("/api/v1/orders/{$order->id}/kitchen-ticket")
            ->assertOk();

        $json = $response->json('data');
        $this->assertEqualsCanonicalizing(
            ['document_type', 'restaurant', 'order', 'table', 'order_note', 'items', 'generated_at'],
            array_keys($json)
        );
        $this->assertEqualsCanonicalizing(['id', 'name'], array_keys($json['restaurant']));
        $this->assertEqualsCanonicalizing(['id', 'status', 'origin', 'created_at'], array_keys($json['order']));
        $this->assertEqualsCanonicalizing(['id', 'name', 'number'], array_keys($json['table']));
        $this->assertSame('Order note', $json['order_note']);
        $this->assertNotNull($json['generated_at']);
    }

    // --- Reprint ---------------------------------------------------

    public function test_served_order_can_be_reprinted(): void
    {
        [, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);
        $order = $this->createWaiterOrder($table, $owner, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);
        $order = $this->advanceOrderTo($order, Order::STATUS_SERVED, $owner);

        $this->actingAs($owner, 'web')->getJson("/api/v1/orders/{$order->id}/kitchen-ticket")->assertOk();
        $this->actingAs($owner, 'web')->getJson("/api/v1/orders/{$order->id}/kitchen-ticket")->assertOk();
    }

    // --- Preview vs print --------------------------------------------

    public function test_preview_does_not_create_a_print_record(): void
    {
        [, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);
        $order = $this->createWaiterOrder($table, $owner, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);

        $this->actingAs($owner, 'web')->getJson("/api/v1/orders/{$order->id}/kitchen-ticket")->assertOk();

        $this->assertDatabaseCount('print_records', 0);
    }

    public function test_print_creates_a_print_record_with_correct_context(): void
    {
        [$organization, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);
        $order = $this->createWaiterOrder($table, $owner, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);

        $response = $this->actingAs($owner, 'web')
            ->postJson("/api/v1/orders/{$order->id}/kitchen-ticket/print")
            ->assertStatus(201);

        $printRecordId = $response->json('data.print_record_id');
        $this->assertNotNull($printRecordId);
        $this->assertNotNull($response->json('data.document.document_type'));

        $printRecord = PrintRecord::query()->findOrFail($printRecordId);
        $this->assertSame(PrintRecord::DOCUMENT_TYPE_KITCHEN_TICKET, $printRecord->document_type);
        $this->assertSame($organization->id, $printRecord->organization_id);
        $this->assertSame($restaurant->id, $printRecord->restaurant_id);
        $this->assertSame($order->id, $printRecord->order_id);
        $this->assertSame($session->id, $printRecord->table_session_id);
        $this->assertSame($owner->id, $printRecord->requested_by_user_id);
        $this->assertNotNull($printRecord->generated_at);
    }

    public function test_reprint_creates_another_print_record(): void
    {
        [, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);
        $order = $this->createWaiterOrder($table, $owner, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);

        $this->actingAs($owner, 'web')->postJson("/api/v1/orders/{$order->id}/kitchen-ticket/print")->assertStatus(201);
        $this->actingAs($owner, 'web')->postJson("/api/v1/orders/{$order->id}/kitchen-ticket/print")->assertStatus(201);
        $this->actingAs($owner, 'web')->postJson("/api/v1/orders/{$order->id}/kitchen-ticket/print")->assertStatus(201);

        $this->assertDatabaseCount('print_records', 3);
    }

    public function test_served_order_kitchen_ticket_can_still_be_printed(): void
    {
        [, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);
        $order = $this->createWaiterOrder($table, $owner, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);
        $order = $this->advanceOrderTo($order, Order::STATUS_SERVED, $owner);

        $this->actingAs($owner, 'web')
            ->postJson("/api/v1/orders/{$order->id}/kitchen-ticket/print")
            ->assertStatus(201);
    }

    // --- Tenant / restaurant isolation ------------------------------

    public function test_other_organization_order_returns_404(): void
    {
        [, $ownerA] = $this->createTenant();
        [, $ownerB, $restaurantB, $rpB] = $this->createTenantWithRestaurantProduct();
        $tableB = $this->createTable($restaurantB);
        $this->openSession($tableB, $ownerB);
        $orderB = $this->createWaiterOrder($tableB, $ownerB, [['restaurant_product_id' => $rpB->id, 'quantity' => 1]]);

        $this->actingAs($ownerA, 'web')
            ->getJson("/api/v1/orders/{$orderB->id}/kitchen-ticket")
            ->assertStatus(404);
    }

    public function test_other_restaurant_same_organization_order_returns_404(): void
    {
        [$organization, $owner, $restaurantA] = $this->createTenant();
        $waiterA = $this->createStaff($organization, $restaurantA, 'waiter', 'W-A');

        $restaurantB = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $productB = $this->createProduct($organization);
        $rpB = $this->createRestaurantProduct($restaurantB, $productB);
        $tableB = $this->createTable($restaurantB);
        $this->openSession($tableB, $owner);
        $orderB = $this->createWaiterOrder($tableB, $owner, [['restaurant_product_id' => $rpB->id, 'quantity' => 1]]);

        $this->actingAs($waiterA, 'web')
            ->getJson("/api/v1/orders/{$orderB->id}/kitchen-ticket")
            ->assertStatus(404);
    }

    // --- Scope vs permission ------------------------------------------

    public function test_staff_in_scope_but_missing_permission_gets_403(): void
    {
        [$organization, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $cashier = $this->createStaff($organization, $restaurant, 'cashier', 'C-1');
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);
        $order = $this->createWaiterOrder($table, $owner, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);

        $this->actingAs($cashier, 'web')
            ->getJson("/api/v1/orders/{$order->id}/kitchen-ticket")
            ->assertStatus(403);
    }

    // --- Permission by role -------------------------------------------

    public function test_kitchen_can_view_and_print_kitchen_ticket(): void
    {
        [$organization, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $kitchen = $this->createStaff($organization, $restaurant, 'kitchen', 'K-1');
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);
        $order = $this->createWaiterOrder($table, $owner, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);

        $this->actingAs($kitchen, 'web')->getJson("/api/v1/orders/{$order->id}/kitchen-ticket")->assertOk();
        $this->actingAs($kitchen, 'web')->postJson("/api/v1/orders/{$order->id}/kitchen-ticket/print")->assertStatus(201);
    }

    public function test_waiter_can_view_and_print_kitchen_ticket(): void
    {
        [$organization, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);
        $order = $this->createWaiterOrder($table, $owner, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);

        $this->actingAs($waiter, 'web')->getJson("/api/v1/orders/{$order->id}/kitchen-ticket")->assertOk();
    }

    public function test_owner_can_print_kitchen_ticket_in_any_restaurant_of_their_organization(): void
    {
        [$organization, $owner, $restaurantA, $rpA] = $this->createTenantWithRestaurantProduct();
        $tableA = $this->createTable($restaurantA);
        $this->openSession($tableA, $owner);
        $orderA = $this->createWaiterOrder($tableA, $owner, [['restaurant_product_id' => $rpA->id, 'quantity' => 1]]);

        $restaurantB = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $productB = $this->createProduct($organization);
        $rpB = $this->createRestaurantProduct($restaurantB, $productB);
        $tableB = $this->createTable($restaurantB);
        $this->openSession($tableB, $owner);
        $orderB = $this->createWaiterOrder($tableB, $owner, [['restaurant_product_id' => $rpB->id, 'quantity' => 1]]);

        $this->actingAs($owner, 'web')->getJson("/api/v1/orders/{$orderA->id}/kitchen-ticket")->assertOk();
        $this->actingAs($owner, 'web')->getJson("/api/v1/orders/{$orderB->id}/kitchen-ticket")->assertOk();
    }
}
