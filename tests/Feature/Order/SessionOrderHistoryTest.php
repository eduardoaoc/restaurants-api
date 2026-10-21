<?php

namespace Tests\Feature\Order;

use App\Actions\Orders\ApproveOrderAction;
use App\Actions\Orders\RejectOrderAction;
use App\Actions\Tables\TransferTableSessionAction;
use App\Models\Order;
use App\Models\Permission;
use App\Models\Restaurant;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithOrders;
use Tests\Concerns\InteractsWithPayments;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class SessionOrderHistoryTest extends TestCase
{
    use InteractsWithOrders, InteractsWithPayments, InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRolesAndPermissions();
    }

    public function test_staff_history_keeps_all_states_and_paginates_without_duplicates_after_insertion(): void
    {
        [$org, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $waiter = $this->createStaff($org, $restaurant, 'waiter', 'W');
        $this->requireOrderApproval($restaurant);
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);
        $ids = [];
        foreach (['waiting_approval', 'confirmed', 'accepted', 'preparing', 'ready', 'served', 'cancelled'] as $status) {
            $order = $this->createCustomerOrder($table, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);
            if ($status === 'cancelled') {
                $order = app(RejectOrderAction::class)->execute($order, $owner);
            } elseif ($status !== 'waiting_approval') {
                $order = app(ApproveOrderAction::class)->execute($order, $owner);
                if ($status !== 'confirmed') {
                    $order = $this->advanceOrderTo($order, $status, $owner);
                }
            }
            $ids[] = $order->id;
        }
        $staff = $this->createWaiterOrder($table, $owner, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);
        $ids[] = $staff->id;
        // Equal timestamps must still yield a stable order.
        Order::query()->whereIn('id', $ids)->update(['created_at' => now()->startOfSecond()]);
        $url = "/api/v1/orders?restaurant_id={$restaurant->id}&table_session_id={$session->id}&per_page=3";
        $first = $this->actingAs($waiter, 'web')->getJson($url)->assertOk();
        $all = $first->json('data.orders');
        $cursor = $first->json('meta.next_before_id');
        $new = $this->createWaiterOrder($table, $owner, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);
        while ($cursor !== null) {
            $page = $this->getJson($url.'&before_id='.$cursor)->assertOk();
            $all = array_merge($all, $page->json('data.orders'));
            $cursor = $page->json('meta.next_before_id');
        }
        $this->assertSame(array_reverse($ids), array_column($all, 'id'));
        $this->assertCount(count($ids), array_unique(array_column($all, 'id')));
        $this->assertNotContains($new->id, array_column($all, 'id'));
        $this->assertContains('waiting_approval', array_column($all, 'status'));
        $this->assertContains('cancelled', array_column($all, 'status'));
        $this->assertContains('served', array_column($all, 'status'));
        $this->getJson('/api/v1/orders?per_page=20')->assertUnprocessable();
        $this->getJson($url.'&before_id=bad')->assertUnprocessable();
    }

    public function test_session_history_survives_transfer_and_never_leaks_into_next_occupation(): void
    {
        [, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $table = $this->createTable($restaurant);
        $target = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);
        $order = $this->createWaiterOrder($table, $owner, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);
        $this->advanceOrderTo($order, 'served', $owner);
        app(TransferTableSessionAction::class)->execute($session, $target, $owner);
        $newSession = $this->openSession($table, $owner);
        $this->actingAs($owner, 'web')->getJson("/api/v1/orders?restaurant_id={$restaurant->id}&table_session_id={$session->id}&per_page=20")
            ->assertOk()->assertJsonCount(1, 'data.orders')->assertJsonPath('data.orders.0.id', $order->id)
            ->assertJsonPath('data.orders.0.table.id', $target->id);
        $this->getJson("/api/v1/orders?restaurant_id={$restaurant->id}&table_session_id={$newSession->id}&per_page=20")
            ->assertOk()->assertJsonCount(0, 'data.orders');
    }

    public function test_history_and_bill_enforce_tenant_restaurant_and_permission_boundaries(): void
    {
        [$org, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);
        $order = $this->createWaiterOrder($table, $owner, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);
        $other = Restaurant::factory()->create(['organization_id' => $org->id]);
        $waiter = $this->createStaff($org, $other, 'waiter', 'OTHER');
        $this->actingAs($waiter, 'web');
        $this->getJson("/api/v1/orders?restaurant_id={$restaurant->id}&table_session_id={$session->id}&per_page=20")->assertOk()->assertJsonCount(0, 'data.orders');
        $this->getJson("/api/v1/orders/{$order->id}")->assertNotFound();
        $this->getJson("/api/v1/table-sessions/{$session->id}/bill")->assertNotFound();
        [, $foreign] = $this->createTenant();
        $this->app['auth']->forgetGuards();
        $this->actingAs($foreign, 'web')->getJson("/api/v1/table-sessions/{$session->id}/bill")->assertNotFound();
    }

    public function test_bill_is_read_only_uses_snapshots_and_canonical_totals_with_partial_payment(): void
    {
        [$org, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $waiter = $this->createStaff($org, $restaurant, 'waiter', 'W');
        $this->requireOrderApproval($restaurant);
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);
        $group = $this->createModifierGroup($rp);
        $option = $this->createModifierOption($group, null, 1.25);
        $payload = [['restaurant_product_id' => $rp->id, 'quantity' => 2, 'modifier_option_ids' => [$option->id]]];
        $charged = $this->createWaiterOrder($table, $owner, $payload);
        $pending = $this->createCustomerOrder($table, $payload);
        $cancelled = $this->createCustomerOrder($table, $payload);
        app(RejectOrderAction::class)->execute($cancelled, $owner);
        $this->recordPayment($session, $owner, '1.00');
        $beforeSession = $session->fresh()->getAttributes();
        $beforeOrders = Order::query()->orderBy('id')->get()->toArray();
        $snapshot = $charged->items->first();
        $rp->update(['price' => '999.99']);
        $option->update(['price_delta' => '99.99']);
        $bill = $this->actingAs($waiter, 'web')->getJson("/api/v1/table-sessions/{$session->id}/bill")->assertOk()
            ->assertJsonPath('data.orders_total', (string) $charged->total)->assertJsonPath('data.paid_total', '1.00')
            ->assertJsonPath('data.payment_status', 'unpaid')->assertJsonCount(3, 'data.orders');
        $this->assertSame([$charged->id], collect($bill->json('data.orders'))->where('billable', true)->pluck('id')->all());
        $item = collect($bill->json('data.orders'))->firstWhere('id', $charged->id)['items'][0];
        $this->assertSame($snapshot->product_name_snapshot, $item['name']);
        $this->assertSame((string) $snapshot->unit_price_snapshot, $item['unit_price']);
        $this->assertSame('1.25', $item['modifiers'][0]['price_delta']);
        $this->assertSame($beforeSession, $session->fresh()->getAttributes());
        $this->assertSame($beforeOrders, Order::query()->orderBy('id')->get()->toArray());
        $this->assertDatabaseCount('payment_records', 1);
        $this->assertSame('waiting_approval', $pending->fresh()->status);
    }

    public function test_bill_permission_does_not_implicitly_grant_payment_permission(): void
    {
        [$org, $owner, $restaurant] = $this->createTenant();
        $session = $this->openSession($this->createTable($restaurant), $owner);
        $waiter = $this->createStaff($org, $restaurant, 'waiter', 'W');
        $role = Role::where('slug', 'waiter')->firstOrFail();
        $role->permissions()->detach(Permission::where('slug', 'record_payments')->value('id'));
        $this->actingAs($waiter, 'web')->getJson("/api/v1/table-sessions/{$session->id}/bill")->assertOk();
        $this->postJson("/api/v1/table-sessions/{$session->id}/payments", ['method' => 'cash', 'amount' => '1.00'])->assertForbidden();
        $user = User::factory()->create();
        $org->users()->attach($user);
        $restaurant->users()->attach($user, ['sub_id' => 'NONE']);
        $this->app['auth']->forgetGuards();
        $this->actingAs($user, 'web')->getJson("/api/v1/table-sessions/{$session->id}/bill")->assertForbidden();
    }
}
