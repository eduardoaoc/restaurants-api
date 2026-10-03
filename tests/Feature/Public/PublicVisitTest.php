<?php

namespace Tests\Feature\Public;

use App\Actions\Orders\RejectOrderAction;
use App\Actions\Tables\CloseTableAction;
use App\Models\ModifierOption;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductTranslation;
use App\Models\Restaurant;
use App\Models\RestaurantProduct;
use App\Models\Table;
use App\Models\TableSession;
use App\Models\User;
use App\Support\Billing\SessionBillCalculator;
use App\Support\Money\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithOrders;
use Tests\Concerns\InteractsWithPayments;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * GET /api/v1/public/visits/{feedbackToken} — post-payment visit summary
 * (CARTA 5.1A).
 */
class PublicVisitTest extends TestCase
{
    use InteractsWithOrders, InteractsWithPayments, InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
    }

    /**
     * @return array{0: Table, 1: TableSession, 2: User, 3: Restaurant, 4: RestaurantProduct}
     */
    private function openTenantSession(): array
    {
        [, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);

        return [$table, $session, $owner, $restaurant, $rp];
    }

    private function servedOrder(Table $table, User $owner, RestaurantProduct $rp, int $quantity = 1, array $modifierOptionIds = []): Order
    {
        $item = ['restaurant_product_id' => $rp->id, 'quantity' => $quantity];

        if ($modifierOptionIds !== []) {
            $item['modifier_option_ids'] = $modifierOptionIds;
        }

        $order = $this->createWaiterOrder($table, $owner, [$item]);

        return $this->advanceOrderTo($order, Order::STATUS_SERVED, $owner);
    }

    private function payInFull(TableSession $session, User $owner): TableSession
    {
        $balance = SessionBillCalculator::summarize($session->refresh())['balanceCents'];
        $this->recordPayment($session, $owner, Money::centsToDecimal($balance));

        return $session->refresh();
    }

    /**
     * @return array{0: Table, 1: TableSession, 2: User, 3: Restaurant, 4: RestaurantProduct}
     */
    private function paidSession(): array
    {
        [$table, $session, $owner, $restaurant, $rp] = $this->openTenantSession();
        $this->servedOrder($table, $owner, $rp);

        return [$table, $this->payInFull($session, $owner), $owner, $restaurant, $rp];
    }

    private function visitUrl(TableSession $session): string
    {
        return "/api/v1/public/visits/{$session->feedback_token}";
    }

    // --- A. Resolution ---------------------------------------------------------

    public function test_unknown_token_returns_404(): void
    {
        $this->getJson('/api/v1/public/visits/'.str_repeat('x', 48))
            ->assertNotFound()
            ->assertJson(['error' => ['code' => 'FEEDBACK_TOKEN_NOT_FOUND']]);
    }

    public function test_integer_session_id_is_not_accepted_as_a_key(): void
    {
        [, $session] = $this->paidSession();

        $this->getJson("/api/v1/public/visits/{$session->id}")
            ->assertNotFound()
            ->assertJson(['error' => ['code' => 'FEEDBACK_TOKEN_NOT_FOUND']]);
    }

    public function test_public_table_token_cannot_substitute_for_the_visit_token(): void
    {
        [$table] = $this->paidSession();

        $this->getJson("/api/v1/public/visits/{$table->public_token}")
            ->assertNotFound()
            ->assertJson(['error' => ['code' => 'FEEDBACK_TOKEN_NOT_FOUND']]);
    }

    // --- B. Eligibility ----------------------------------------------------------

    public function test_unpaid_session_returns_409_without_leaking_orders(): void
    {
        [$table, $session, $owner, , $rp] = $this->openTenantSession();
        $this->servedOrder($table, $owner, $rp);

        $response = $this->getJson($this->visitUrl($session->refresh()))
            ->assertStatus(409)
            ->assertExactJson(['error' => [
                'code' => 'TABLE_SESSION_NOT_PAID_FOR_VISIT',
                'message' => 'This visit has not been paid yet.',
            ]]);

        $this->assertArrayNotHasKey('data', $response->json());
    }

    // --- C/D. Paid, active and closed ------------------------------------------

    public function test_paid_active_session_returns_200(): void
    {
        [$table, $session, , $restaurant] = $this->paidSession();

        $this->getJson($this->visitUrl($session))
            ->assertOk()
            ->assertJsonStructure(['data' => [
                'restaurant' => ['name'],
                'table' => ['name'],
                'visit' => ['active', 'paid_at', 'closed_at'],
                'orders' => [['order_number', 'created_at', 'total', 'items' => [['name', 'quantity', 'unit_price', 'modifiers', 'line_total']]]],
                'summary' => ['total'],
            ]])
            ->assertJson(['data' => [
                'restaurant' => ['name' => $restaurant->name],
                'table' => ['name' => $table->name],
                'visit' => ['active' => true, 'closed_at' => null],
            ]])
            ->assertJsonPath('data.visit.paid_at', fn ($paidAt) => $paidAt !== null);
    }

    public function test_paid_and_closed_session_remains_accessible_by_its_own_token(): void
    {
        [$table, $session, $owner] = $this->paidSession();
        $closed = app(CloseTableAction::class)->execute($session, $owner);

        // The table is reused by a new visit; the old token still resolves
        // only the old, closed visit.
        $this->openSession($table, $owner);

        $this->getJson($this->visitUrl($closed))
            ->assertOk()
            ->assertJson(['data' => ['visit' => ['active' => false]]])
            ->assertJsonPath('data.visit.closed_at', fn ($closedAt) => $closedAt !== null)
            ->assertJsonCount(1, 'data.orders');
    }

    // --- E/F/G. Billable orders only -------------------------------------------

    public function test_every_billable_status_is_included_in_chronological_order(): void
    {
        [$table, $session, $owner, , $rp] = $this->openTenantSession();

        $expected = [];
        foreach ([Order::STATUS_CONFIRMED, Order::STATUS_ACCEPTED, Order::STATUS_PREPARING, Order::STATUS_READY, Order::STATUS_SERVED] as $i => $status) {
            $order = $this->createWaiterOrder($table, $owner, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);
            if ($status !== Order::STATUS_CONFIRMED) {
                $order = $this->advanceOrderTo($order, $status, $owner);
            }
            // Shuffled timestamps (with one tie): created_at drives the
            // order, id only breaks ties.
            $order->forceFill(['created_at' => now()->startOfMinute()->subMinutes([3, 5, 1, 5, 2][$i])])->saveQuietly();
            $expected[] = $order->refresh();
        }

        $session = $this->payInFull($session, $owner);

        usort($expected, fn (Order $a, Order $b) => [$a->created_at, $a->id] <=> [$b->created_at, $b->id]);

        $this->getJson($this->visitUrl($session))
            ->assertOk()
            ->assertJsonCount(5, 'data.orders')
            ->assertJsonPath('data.orders.*.order_number', array_map(fn (Order $o) => "#{$o->id}", $expected));
    }

    public function test_waiting_approval_and_cancelled_orders_are_excluded(): void
    {
        [$table, $session, $owner, $restaurant, $rp] = $this->openTenantSession();
        $served = $this->servedOrder($table, $owner, $rp);

        $this->requireOrderApproval($restaurant);
        $waiting = $this->createCustomerOrder($table, [['restaurant_product_id' => $rp->id, 'quantity' => 3]]);
        $toCancel = $this->createCustomerOrder($table, [['restaurant_product_id' => $rp->id, 'quantity' => 4]]);
        $cancelled = app(RejectOrderAction::class)->execute($toCancel, $owner);

        $this->assertSame(Order::STATUS_WAITING_APPROVAL, $waiting->status);
        $this->assertSame(Order::STATUS_CANCELLED, $cancelled->status);

        $session = $this->payInFull($session, $owner);

        $response = $this->getJson($this->visitUrl($session))->assertOk();

        $this->assertSame(["#{$served->id}"], $response->json('data.orders.*.order_number'));
        $this->assertSame((string) $served->total, $response->json('data.summary.total'));
    }

    // --- H/I. Snapshots -------------------------------------------------------------

    public function test_items_and_modifiers_render_the_persisted_snapshots(): void
    {
        [$table, $session, $owner, , $rp] = $this->openTenantSession();
        $group = $this->createModifierGroup($rp, maxSelect: 2, translations: [['locale' => 'en', 'name' => 'Extras']]);
        $bacon = $this->createModifierOption($group, priceDelta: 1.5, translations: [['locale' => 'en', 'name' => 'Bacon']]);
        $cheese = $this->createModifierOption($group, priceDelta: 0.25, translations: [['locale' => 'en', 'name' => 'Cheese']]);

        $order = $this->servedOrder($table, $owner, $rp, 2, [$bacon->id, $cheese->id]);
        $session = $this->payInFull($session, $owner);

        $item = OrderItem::query()->where('order_id', $order->id)->with('modifiers')->sole();

        $this->getJson($this->visitUrl($session))
            ->assertOk()
            ->assertExactJson(['data' => [
                'restaurant' => ['name' => $session->restaurant->name],
                'table' => ['name' => $table->name],
                'visit' => [
                    'active' => true,
                    'paid_at' => $session->paid_at->toJSON(),
                    'closed_at' => null,
                ],
                'orders' => [[
                    'order_number' => "#{$order->id}",
                    'created_at' => $order->created_at->toJSON(),
                    'total' => '23.50',
                    'items' => [[
                        'name' => $item->product_name_snapshot,
                        'quantity' => 2,
                        'unit_price' => '10.00',
                        'modifiers' => $item->modifiers->sortBy('id')->map(fn ($m) => [
                            'group_name' => 'Extras',
                            'name' => $m->modifier_option_name_snapshot,
                            'price_delta' => (string) $m->price_delta_snapshot,
                        ])->values()->all(),
                        'line_total' => '23.50',
                    ]],
                ]],
                'summary' => ['total' => '23.50'],
                'google_review' => ['available' => false, 'url' => null],
            ]]);

        $this->assertEqualsCanonicalizing(['Bacon', 'Cheese'], $item->modifiers->pluck('modifier_option_name_snapshot')->all());
    }

    public function test_catalog_changes_after_the_order_do_not_rewrite_history(): void
    {
        [$table, $session, $owner, , $rp] = $this->openTenantSession();
        $group = $this->createModifierGroup($rp, translations: [['locale' => 'en', 'name' => 'Extras']]);
        $bacon = $this->createModifierOption($group, priceDelta: 1.0, translations: [['locale' => 'en', 'name' => 'Bacon']]);

        $order = $this->servedOrder($table, $owner, $rp, 1, [$bacon->id]);
        $session = $this->payInFull($session, $owner);
        $snapshotName = OrderItem::query()->where('order_id', $order->id)->value('product_name_snapshot');

        // Rename/reprice everything the order referenced, and make it unavailable.
        ProductTranslation::query()->where('product_id', $rp->product_id)->update(['name' => 'Renamed Product']);
        $rp->update(['price' => 99.99, 'available' => false]);
        ModifierOption::query()->whereKey($bacon->id)->update(['price_delta' => 50.00]);
        DB::table('modifier_option_translations')->where('modifier_option_id', $bacon->id)->update(['name' => 'Renamed Option']);
        DB::table('modifier_group_translations')->where('modifier_group_id', $group->id)->update(['name' => 'Renamed Group']);

        $this->getJson($this->visitUrl($session))
            ->assertOk()
            ->assertJsonPath('data.orders.0.items.0.name', $snapshotName)
            ->assertJsonPath('data.orders.0.items.0.unit_price', '10.00')
            ->assertJsonPath('data.orders.0.items.0.modifiers.0.group_name', 'Extras')
            ->assertJsonPath('data.orders.0.items.0.modifiers.0.name', 'Bacon')
            ->assertJsonPath('data.orders.0.items.0.modifiers.0.price_delta', '1.00')
            ->assertJsonPath('data.orders.0.items.0.line_total', '11.00')
            ->assertJsonPath('data.summary.total', '11.00');

        $this->assertNotSame('Renamed Product', $snapshotName);
    }

    // --- J. Total ---------------------------------------------------------------------

    public function test_summary_total_matches_session_bill_calculator_and_bill_endpoint(): void
    {
        [$table, $session, $owner, , $rp] = $this->openTenantSession();
        $group = $this->createModifierGroup($rp);
        $option = $this->createModifierOption($group, priceDelta: 0.35);

        $this->servedOrder($table, $owner, $rp, 3, [$option->id]);
        $this->servedOrder($table, $owner, $rp, 1);
        $confirmed = $this->createWaiterOrder($table, $owner, [['restaurant_product_id' => $rp->id, 'quantity' => 2]]);
        $session = $this->payInFull($session, $owner);

        $expected = Money::centsToDecimal(SessionBillCalculator::summarize($session)['ordersTotalCents']);

        $response = $this->getJson($this->visitUrl($session))->assertOk();

        $this->assertSame('61.05', $expected);
        $this->assertSame($expected, $response->json('data.summary.total'));
        $this->assertContains("#{$confirmed->id}", $response->json('data.orders.*.order_number'));

        $this->withHeader('Origin', 'http://localhost:5173')
            ->actingAs($owner, 'web')
            ->getJson("/api/v1/table-sessions/{$session->id}/bill")
            ->assertOk()
            ->assertJsonPath('data.orders_total', $expected);
    }

    // --- K. Sanitization -------------------------------------------------------------

    public function test_response_contains_no_internal_or_sensitive_data(): void
    {
        [$table, $session, $owner] = $this->paidSession();

        $response = $this->getJson($this->visitUrl($session))->assertOk();
        $body = $response->getContent();

        $forbiddenKeys = [
            'id', 'table_session_id', 'restaurant_id', 'table_id', 'organization_id', 'user_id',
            'assigned_waiter_user_id', 'created_by_user_id', 'approved_by_user_id', 'opened_by_user_id',
            'closed_by_user_id', 'product_id', 'restaurant_product_id', 'modifier_group_id',
            'modifier_option_id', 'payments', 'payment_records', 'method', 'reference', 'note',
            'customer_note', 'internal_notes', 'email', 'permissions', 'status', 'payment_status',
            'feedback_token', 'public_token', 'origin',
        ];

        $keys = [];
        $collect = function (array $node) use (&$collect, &$keys) {
            foreach ($node as $key => $value) {
                if (is_string($key)) {
                    $keys[] = $key;
                }
                if (is_array($value)) {
                    $collect($value);
                }
            }
        };
        $collect($response->json());

        $this->assertSame([], array_values(array_intersect($forbiddenKeys, $keys)));
        $this->assertStringNotContainsString($session->feedback_token, $body);
        $this->assertStringNotContainsString($table->public_token, $body);
        $this->assertStringNotContainsString($owner->email, $body);
        $this->assertStringNotContainsString('cash', $body);
    }

    public function test_a_visits_token_never_resolves_another_visits_data(): void
    {
        [$tableA, $sessionA] = $this->paidSession();
        [$tableB, $sessionB] = $this->paidSession();

        $a = $this->getJson($this->visitUrl($sessionA))->assertOk();
        $b = $this->getJson($this->visitUrl($sessionB))->assertOk();

        $orderA = Order::query()->where('table_session_id', $sessionA->id)->sole();
        $orderB = Order::query()->where('table_session_id', $sessionB->id)->sole();

        $this->assertSame($tableA->name, $a->json('data.table.name'));
        $this->assertSame($sessionA->restaurant->name, $a->json('data.restaurant.name'));
        $this->assertSame(["#{$orderA->id}"], $a->json('data.orders.*.order_number'));
        $this->assertSame(["#{$orderB->id}"], $b->json('data.orders.*.order_number'));
        $this->assertSame($sessionB->restaurant->name, $b->json('data.restaurant.name'));
        $this->assertNotSame($tableA->name.$sessionA->restaurant->name, $tableB->name.$sessionB->restaurant->name);
    }

    public function test_endpoint_does_not_require_or_use_an_admin_session(): void
    {
        [, $session, $owner] = $this->paidSession();

        $anonymous = $this->getJson($this->visitUrl($session))->assertOk()->json();
        $withAdmin = $this->actingAs($owner)->getJson($this->visitUrl($session))->assertOk()->json();

        $this->assertSame($anonymous, $withAdmin);
    }

    // --- L. Queries -------------------------------------------------------------------

    public function test_query_count_does_not_grow_with_orders_items_or_modifiers(): void
    {
        $countFor = function (int $orders): int {
            [$table, $session, $owner, , $rp] = $this->openTenantSession();
            $group = $this->createModifierGroup($rp, maxSelect: 2);
            $options = [$this->createModifierOption($group, priceDelta: 0.5)->id, $this->createModifierOption($group, priceDelta: 1.0)->id];
            $other = $this->createRestaurantProduct($session->restaurant, $this->createProduct($session->restaurant->organization));

            for ($i = 0; $i < $orders; $i++) {
                $order = $this->createWaiterOrder($table, $owner, [
                    ['restaurant_product_id' => $rp->id, 'quantity' => 2, 'modifier_option_ids' => $options],
                    ['restaurant_product_id' => $other->id, 'quantity' => 1],
                ]);
                $this->advanceOrderTo($order, Order::STATUS_SERVED, $owner);
            }
            $session = $this->payInFull($session, $owner);

            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson($this->visitUrl($session))->assertOk()->assertJsonCount($orders, 'data.orders');
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        };

        $this->assertSame($countFor(1), $countFor(6));
    }

    // --- M. Throttle ------------------------------------------------------------------

    public function test_exceeding_the_public_feedback_limiter_returns_rate_limit_exceeded(): void
    {
        [, $session] = $this->paidSession();
        [, $otherSession] = $this->paidSession();

        for ($i = 0; $i < 10; $i++) {
            $this->getJson($this->visitUrl($session))->assertOk();
        }

        $this->getJson($this->visitUrl($session))
            ->assertStatus(429)
            ->assertJson(['error' => ['code' => 'RATE_LIMIT_EXCEEDED']]);

        // Keyed by IP + token: another visit's token is unaffected.
        $this->getJson($this->visitUrl($otherSession))->assertOk();
    }

    // --- Feedback contract stays independent ------------------------------------------

    public function test_feedback_context_contract_is_unchanged_and_has_no_orders(): void
    {
        [$table, $session, , $restaurant] = $this->paidSession();

        $this->getJson("/api/v1/public/feedback/{$session->feedback_token}")
            ->assertOk()
            ->assertExactJson(['data' => [
                'already_submitted' => false,
                'restaurant' => ['name' => $restaurant->name],
                'table' => ['name' => $table->name],
            ]]);
    }
}
