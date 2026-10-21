<?php

namespace Tests\Feature\RestaurantProduct;

use App\Actions\Catalog\UpdateRestaurantProductAction;
use App\Actions\Orders\ApproveOrderAction;
use App\Models\CategoryProduct;
use App\Models\Order;
use App\Models\Restaurant;
use App\Models\RestaurantActivityEvent;
use App\Models\Role;
use Database\Seeders\ProductAvailabilityPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithOrders;
use Tests\Concerns\InteractsWithPublicVisits;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class ProductAvailabilityTest extends TestCase
{
    use InteractsWithOrders, InteractsWithPublicVisits, InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    public function test_waiter_toggles_idempotently_without_catalog_edit_permission(): void
    {
        [$org, , $r, $rp] = $this->createTenantWithRestaurantProduct();
        $w = $this->createStaff($org, $r, 'waiter', 'W1');
        $before = $rp->product->attributesToArray();
        $this->actingAs($w, 'web')->getJson("/api/v1/restaurants/{$r->id}/products")->assertOk();
        foreach ([false, false, true] as $available) {
            $this->patchJson("/api/v1/restaurant-products/{$rp->id}/availability", ['available' => $available])->assertOk()
                ->assertJsonPath('data.restaurant_product.available', $available)->assertJsonPath('data.restaurant_product.price', '10.00');
        }
        $this->assertSame($before, $rp->product->fresh()->attributesToArray());
        $this->assertSame(2, RestaurantActivityEvent::whereIn('type', ['product.marked_available', 'product.marked_unavailable'])->count());
        $this->patchJson("/api/v1/restaurant-products/{$rp->id}", ['price' => 1])->assertForbidden();
        $this->patchJson("/api/v1/restaurant-products/{$rp->id}/availability", ['available' => false, 'price' => 1])->assertUnprocessable();
        $this->assertTrue($rp->fresh()->available);
    }

    #[\PHPUnit\Framework\Attributes\TestWith(['kitchen'])]
    #[\PHPUnit\Framework\Attributes\TestWith(['cashier'])]
    public function test_kitchen_and_cashier_have_no_permission(string $role): void
    {
        [$org, , $r, $rp] = $this->createTenantWithRestaurantProduct();
        $this->actingAs($this->createStaff($org, $r, $role, $role), 'web')->patchJson("/api/v1/restaurant-products/{$rp->id}/availability", ['available' => false])->assertForbidden();
        $this->assertTrue($rp->fresh()->available);
    }

    public function test_other_restaurant_and_tenant_are_protected_and_shared_product_stays_local(): void
    {
        [$org, , $r, $rp] = $this->createTenantWithRestaurantProduct();
        $other = Restaurant::factory()->create(['organization_id' => $org->id]);
        $otherRp = $this->createRestaurantProduct($other, $rp->product);
        [, , , $foreign] = $this->createTenantWithRestaurantProduct();
        $w = $this->createStaff($org, $r, 'waiter', 'W1');
        $this->actingAs($w, 'web')->patchJson("/api/v1/restaurant-products/{$otherRp->id}/availability", ['available' => false])->assertForbidden();
        $this->patchJson("/api/v1/restaurant-products/{$foreign->id}/availability", ['available' => false])->assertNotFound();
        $this->patchJson("/api/v1/restaurant-products/{$rp->id}/availability", ['available' => false])->assertOk();
        $this->assertTrue($otherRp->fresh()->available);
        $this->assertTrue($foreign->fresh()->available);
    }

    public function test_public_menu_is_fresh_after_old_catalog_endpoint_and_restricted_restore(): void
    {
        [, $owner, $r, $rp] = $this->createTenantWithRestaurantProduct();
        $table = $this->createTable($r);
        $category = $this->createCategory($this->createMenu($r));
        CategoryProduct::create(['category_id' => $category->id, 'restaurant_product_id' => $rp->id, 'sort_order' => 0]);
        $url = "/api/v1/public/tables/{$table->public_token}/menu";
        $this->getJson($url)->assertOk()->assertJsonCount(1, 'data.menu.categories.0.products');
        $this->actingAs($owner, 'web')->patchJson("/api/v1/restaurant-products/{$rp->id}", ['available' => false])->assertOk()->assertJsonPath('data.restaurant_product.available', false);
        $response = $this->getJson($url)->assertOk()->assertJsonCount(0, 'data.menu.categories');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->patchJson("/api/v1/restaurant-products/{$rp->id}/availability", ['available' => true])->assertOk();
        $this->getJson($url)->assertOk()->assertJsonPath('data.menu.categories.0.products.0.restaurant_product_id', $rp->id);
    }

    public function test_qr_old_cart_and_mixed_order_are_revalidated_atomically(): void
    {
        [$org, $owner, $r, $rp] = $this->createTenantWithRestaurantProduct();
        $second = $this->createRestaurantProduct($r, $this->createProduct($org));
        $table = $this->createTable($r);
        $this->openSession($table, $owner);
        $payload = ['items' => [['restaurant_product_id' => $second->id, 'quantity' => 1], ['restaurant_product_id' => $rp->id, 'quantity' => 2]]];
        app(UpdateRestaurantProductAction::class)->execute($rp, $owner, ['available' => false]);
        $this->postPublicJson("/api/v1/public/tables/{$table->public_token}/orders", $payload, ['Idempotency-Key' => 'old-cart'])->assertUnprocessable()->assertJsonPath('error.code', 'INVALID_ORDER_ITEM');
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_items', 0);
        app(UpdateRestaurantProductAction::class)->execute($rp, $owner, ['available' => true]);
        $this->postPublicJson("/api/v1/public/tables/{$table->public_token}/orders", $payload, ['Idempotency-Key' => 'old-cart'])->assertCreated();
    }

    public function test_staff_also_rejects_unavailable_foreign_and_arbitrary_product_ids(): void
    {
        [, $owner, $r, $rp] = $this->createTenantWithRestaurantProduct();
        $table = $this->createTable($r);
        $this->openSession($table, $owner);
        [, , , $foreign] = $this->createTenantWithRestaurantProduct();
        app(UpdateRestaurantProductAction::class)->execute($rp, $owner, ['available' => false]);
        $this->actingAs($owner, 'web');
        foreach ([$rp->id, $foreign->id, 999999] as $id) {
            $this->postJson("/api/v1/tables/{$table->id}/orders", ['items' => [['restaurant_product_id' => $id, 'quantity' => 1]]])->assertUnprocessable()->assertJsonPath('error.code', 'INVALID_ORDER_ITEM');
        }
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_replay_snapshots_bill_and_pending_approval_preserve_the_contract(): void
    {
        [, $owner, $r, $rp] = $this->createTenantWithRestaurantProduct();
        $this->requireOrderApproval($r);
        $table = $this->createTable($r);
        $session = $this->openSession($table, $owner);
        $payload = ['items' => [['restaurant_product_id' => $rp->id, 'quantity' => 2, 'note' => 'Keep note']]];
        $url = "/api/v1/public/tables/{$table->public_token}/orders";
        $first = $this->postPublicJson($url, $payload, ['Idempotency-Key' => 'original'])->assertCreated();
        $order = Order::findOrFail($first->json('data.id'));
        $snapshot = $order->items()->first()->attributesToArray();
        app(UpdateRestaurantProductAction::class)->execute($rp, $owner, ['available' => false, 'price' => 99]);
        $this->assertSame('waiting_approval', $order->fresh()->status);
        $this->postPublicJson($url, $payload, ['Idempotency-Key' => 'original'])->assertOk()->assertJsonPath('data.id', $order->id)->assertJsonPath('data.total', '20.00');
        $this->postPublicJson($url, $payload, ['Idempotency-Key' => 'new'])->assertUnprocessable();
        app(ApproveOrderAction::class)->execute($order, $owner);
        $this->assertSame('confirmed', $order->fresh()->status);
        $this->assertSame($snapshot, $order->items()->first()->attributesToArray());
        $billUrl = "/api/v1/table-sessions/{$session->id}/bill";
        $bill = $this->actingAs($owner, 'web')->getJson($billUrl)->assertOk()->json('data');
        app(UpdateRestaurantProductAction::class)->execute($rp, $owner, ['available' => true, 'price' => 55]);
        $this->assertSame($bill, $this->getJson($billUrl)->assertOk()->json('data'));
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_additive_provisioning_is_idempotent_and_preserves_grants(): void
    {
        $before = Role::where('slug', 'waiter')->firstOrFail()->permissions()->pluck('slug')->sort()->values()->all();
        $this->seed(ProductAvailabilityPermissionSeeder::class);
        $this->seed(ProductAvailabilityPermissionSeeder::class);
        $this->assertSame($before, Role::where('slug', 'waiter')->firstOrFail()->permissions()->pluck('slug')->sort()->values()->all());
        foreach (['kitchen', 'cashier'] as $slug) {
            $this->assertFalse(Role::where('slug', $slug)->firstOrFail()->permissions()->where('slug', 'manage_product_availability')->exists());
        }
    }
}
