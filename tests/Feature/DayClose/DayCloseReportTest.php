<?php

namespace Tests\Feature\DayClose;

use App\Actions\Catalog\UpdateRestaurantProductAction;
use App\Actions\Orders\RejectOrderAction;
use App\Actions\Orders\TransitionOrderStatusAction;
use App\Actions\Tables\CloseTableAction;
use App\Models\Order;
use App\Models\Restaurant;
use App\Models\RestaurantProduct;
use App\Models\TableSession;
use App\Models\User;
use App\Support\Billing\SessionBillCalculator;
use App\Support\DayClose\DayCloseSnapshotBuilder;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithDayClose;
use Tests\Concerns\InteractsWithOrders;
use Tests\Concerns\InteractsWithPayments;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * CARTA 9.1A — report sections: orders, sessions, guests, top products,
 * peak hour, product availability, feedback, delays.
 */
class DayCloseReportTest extends TestCase
{
    use InteractsWithDayClose, InteractsWithOrders, InteractsWithPayments, InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    /**
     * Pay the session's whole balance and close it.
     */
    private function settle(TableSession $session, User $actor): void
    {
        $balance = SessionBillCalculator::summarize($session->refresh())['balanceCents'];

        if ($balance > 0) {
            $this->recordPayment($session, $actor, Money::centsToDecimal($balance));
        }

        app(CloseTableAction::class)->execute($session->refresh(), $actor);
    }

    private function product(Restaurant $restaurant, string $name, float $price = 5.0): RestaurantProduct
    {
        return $this->createRestaurantProduct($restaurant, $this->createProduct($restaurant->organization, $name, [['locale' => 'en', 'name' => $name]]), $price);
    }

    public function test_orders_sessions_guests_and_top_products(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $this->setDefaultOpeningFloat($restaurant, '0.00');
        $this->requireOrderApproval($restaurant);
        $paella = $this->product($restaurant, 'Paella');
        $agua = $this->product($restaurant, 'Agua', 2.0);
        $tarta = $this->product($restaurant, 'Tarta');

        $this->at('2026-10-02 18:00:00');
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner, 4);
        $this->advanceOrderTo($this->createWaiterOrder($table, $owner, [['restaurant_product_id' => $paella->id, 'quantity' => 3], ['restaurant_product_id' => $agua->id, 'quantity' => 2]]), Order::STATUS_SERVED, $owner);
        $this->advanceOrderTo($this->createWaiterOrder($table, $owner, [['restaurant_product_id' => $agua->id, 'quantity' => 3]]), Order::STATUS_SERVED, $owner);
        // Rejected customer order: registered, never valid, never "sold".
        $rejected = $this->createCustomerOrder($table, [['restaurant_product_id' => $tarta->id, 'quantity' => 10]]);
        app(RejectOrderAction::class)->execute($rejected, $owner);
        $this->settle($session, $owner);

        $other = $this->createTable($restaurant);
        $otherSession = $this->openSession($other, $owner, 2);
        $this->advanceOrderTo($this->createWaiterOrder($other, $owner, [['restaurant_product_id' => $tarta->id, 'quantity' => 1]]), Order::STATUS_SERVED, $owner);
        $this->settle($otherSession, $owner);

        $this->at('2026-10-02 21:00:00');
        $data = $this->closeDay($restaurant, $owner)->assertCreated()->json('data');

        $this->assertSame(4, $data['orders_registered']);
        $this->assertSame(3, $data['orders_valid']);
        $this->assertSame(3, $data['orders_served']);
        $this->assertSame(1, $data['orders_rejected']);
        $this->assertSame(2, $data['sessions_opened']);
        $this->assertSame(2, $data['sessions_closed']);
        $this->assertSame(6, $data['guests']);
        $this->assertSame([
            ['product_id' => $agua->product_id, 'name' => 'Agua', 'quantity' => 5],
            ['product_id' => $paella->product_id, 'name' => 'Paella', 'quantity' => 3],
            ['product_id' => $tarta->product_id, 'name' => 'Tarta', 'quantity' => 1],
        ], $data['report']['products']['top']);
        $this->assertSame('Agua', $data['report']['summary']['top_product']['name']);
    }

    public function test_top_products_limit_and_deterministic_tie_break(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $this->at('2026-10-02 18:00:00');
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);
        $items = collect(['F', 'B', 'E', 'A', 'D', 'C'])->map(fn ($name) => ['restaurant_product_id' => $this->product($restaurant, $name)->id, 'quantity' => 1])->all();
        $this->createWaiterOrder($table, $owner, $items);
        $this->at('2026-10-02 19:00:00');

        $top = $this->dayClosePreview($restaurant, $owner)['products']['top'];
        $this->assertSame(['A', 'B', 'C', 'D', 'E'], array_column($top, 'name'));
    }

    public function test_peak_hour_uses_the_local_calendar_hour_across_days(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $this->setDefaultOpeningFloat($restaurant, '0.00');
        $this->at('2026-10-02 21:00:00');
        $this->closeDay($restaurant, $owner)->assertCreated();

        // 10-03 19:xxZ = 21:xx Madrid (2 sessions); 10-04 19:xxZ = 21:xx Madrid (3 sessions).
        foreach (['2026-10-03 19:05:00', '2026-10-03 19:40:00', '2026-10-04 19:01:00', '2026-10-04 19:20:00', '2026-10-04 19:50:00'] as $instant) {
            $this->at($instant);
            $this->servedPaidSession($restaurant, $owner);
        }
        $this->at('2026-10-04 21:00:00');

        $this->assertSame(['local_hour' => '2026-10-04T21:00', 'sessions_started' => 3], $this->dayClosePreview($restaurant, $owner)['operations']['peak_hour']);
    }

    public function test_product_availability_timeline(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $this->setDefaultOpeningFloat($restaurant, '0.00');
        $update = app(UpdateRestaurantProductAction::class);
        $croquetas = $this->product($restaurant, 'Croquetas');
        $paella = $this->product($restaurant, 'Paella');
        $vino = $this->product($restaurant, 'Vino');
        // Unavailable since before any recorded event: unknown start.
        $legacy = $this->createRestaurantProduct($restaurant, $this->createProduct($restaurant->organization, 'Legacy'), 5.0, available: false);

        $this->at('2026-10-02 18:00:00');
        $update->execute($croquetas, $owner, ['available' => false]);
        $this->at('2026-10-02 19:30:00');
        $update->execute($croquetas->refresh(), $owner, ['available' => true]);
        $this->at('2026-10-02 20:00:00');
        $update->execute($paella, $owner, ['available' => false]);
        // Vino: toggled off/on before the period start of the NEXT close.
        $this->at('2026-10-02 21:00:00');

        $items = collect($this->dayClosePreview($restaurant, $owner)['product_availability']['items'])->keyBy('product_name_snapshot');

        $this->assertSame([
            'restaurant_product_id' => $croquetas->id,
            'product_name_snapshot' => 'Croquetas',
            'unavailable_since_known' => true,
            'unavailable_at' => '2026-10-02T18:00:00Z',
            'unavailable_by_name' => $owner->name,
            'available_again_at' => '2026-10-02T19:30:00Z',
            'available_again_by_name' => $owner->name,
            'duration_seconds' => 5400,
            'state_at_close' => 'available',
        ], $items['Croquetas']);

        $this->assertSame('unavailable', $items['Paella']['state_at_close']);
        $this->assertSame('2026-10-02T20:00:00Z', $items['Paella']['unavailable_at']);
        $this->assertNull($items['Paella']['available_again_at']);
        $this->assertNull($items['Paella']['duration_seconds']);

        $this->assertFalse($items['Legacy']['unavailable_since_known']);
        $this->assertNull($items['Legacy']['unavailable_at']);
        $this->assertSame('unavailable', $items['Legacy']['state_at_close']);
        $this->assertFalse($items->has('Vino'));

        $data = $this->closeDay($restaurant, $owner)->assertCreated()->json('data');
        $this->assertSame(3, $data['unavailable_products_count']);
        $this->assertTrue($data['has_incidents']);

        // Next period: Paella (unavailable since a known PRIOR event) comes back.
        $this->at('2026-10-03 18:00:00');
        $update->execute($paella->refresh(), $owner, ['available' => true]);
        $update->execute($vino, $owner, ['available' => false]);
        $this->at('2026-10-03 18:10:00');
        $update->execute($vino->refresh(), $owner, ['available' => true]);
        $this->at('2026-10-03 21:00:00');

        $next = collect($this->dayClosePreview($restaurant, $owner)['product_availability']['items'])->keyBy('product_name_snapshot');
        $this->assertSame('2026-10-02T20:00:00Z', $next['Paella']['unavailable_at']);
        $this->assertTrue($next['Paella']['unavailable_since_known']);
        $this->assertSame('2026-10-03T18:00:00Z', $next['Paella']['available_again_at']);
        $this->assertSame(22 * 3600, $next['Paella']['duration_seconds']);
        $this->assertSame(600, $next['Vino']['duration_seconds']);
    }

    public function test_feedback_summary_critical_and_attention_without_pii(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $this->setDefaultOpeningFloat($restaurant, '0.00');
        $this->at('2026-10-02 18:00:00');
        $s1 = $this->servedPaidSession($restaurant, $owner);
        $s2 = $this->servedPaidSession($restaurant, $owner);
        $s3 = $this->servedPaidSession($restaurant, $owner);
        $s4 = $this->servedPaidSession($restaurant, $owner);
        $this->createFeedback($s1, ['overall' => 5]);
        $this->createFeedback($s2, ['overall' => 2, 'food' => 1, 'service' => 3, 'wait_time' => 2], 'Comida fría');
        $this->createFeedback($s3, ['overall' => 4, 'food' => 5, 'service' => 2, 'wait_time' => 5]);
        $this->createFeedback($s4, ['overall' => 3]);
        $this->at('2026-10-02 21:00:00');

        $data = $this->closeDay($restaurant, $owner)->assertCreated()->json('data');

        $this->assertSame(4, $data['feedback_count']);
        $this->assertSame('3.50', $data['feedback_avg_overall']);
        $this->assertSame(1, $data['critical_feedback_count']);
        $this->assertSame(1, $data['low_dimension_feedback_count']);
        $this->assertTrue($data['has_incidents']);

        $critical = $data['report']['feedback']['critical'];
        $this->assertCount(1, $critical);
        $this->assertSame(['overall' => 2, 'food' => 1, 'service' => 3, 'wait_time' => 2], $critical[0]['ratings']);
        $this->assertSame('Comida fría', $critical[0]['experience_comment']);
        $this->assertSame($s2->id, $critical[0]['table_session_id']);
        $this->assertSame($s2->table_id, $critical[0]['table']['id']);
        $this->assertSame(['overall' => 4, 'food' => 5, 'service' => 2, 'wait_time' => 5], $data['report']['feedback']['attention'][0]['ratings']);

        $json = json_encode($data['report']);
        $this->assertStringNotContainsString('Private-Surname', $json);
        $this->assertStringNotContainsString('ana.private@example.com', $json);
        $this->assertStringNotContainsString('"Ana"', $json);
        $this->assertStringNotContainsString('first_name', $json);
    }

    public function test_low_dimension_feedback_alone_is_not_an_incident(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $this->setDefaultOpeningFloat($restaurant, '0.00');
        $this->at('2026-10-02 18:00:00');
        $session = $this->servedPaidSession($restaurant, $owner);
        $this->createFeedback($session, ['overall' => 4, 'food' => 2]);
        $this->at('2026-10-02 21:00:00');

        $this->closeDay($restaurant, $owner)->assertCreated()
            ->assertJsonPath('data.critical_feedback_count', 0)
            ->assertJsonPath('data.low_dimension_feedback_count', 1)
            ->assertJsonPath('data.has_incidents', false);
    }

    public function test_delays_for_all_three_stages_with_neutral_attribution(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $this->setDefaultOpeningFloat($restaurant, '0.00');
        $kitchen = $this->createStaff($organization, $restaurant, 'kitchen', 'K-1', name: 'Pablo Cocina');
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1', name: 'Lorena Sala');
        $paella = $this->product($restaurant, 'Paella');
        $transition = app(TransitionOrderStatusAction::class);

        $this->at('2026-10-02 18:00:00');
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $waiter);
        $order = $this->createWaiterOrder($table, $waiter, [['restaurant_product_id' => $paella->id, 'quantity' => 1]]);
        $this->at('2026-10-02 18:12:00'); // accept after 12 min (> 10)
        $order = $transition->accept($order, $kitchen);
        $order = $transition->startPreparing($order, $kitchen);
        $this->at('2026-10-02 18:47:00'); // prepared in 35 min (> 30)
        $order = $transition->markReady($order, $kitchen);
        $this->at('2026-10-02 18:55:00'); // served after 8 min (<= 10): no delay
        $transition->serve($order, $waiter);

        $second = $this->createWaiterOrder($table, $waiter, [['restaurant_product_id' => $paella->id, 'quantity' => 1]]);
        $this->advanceOrderTo($second, Order::STATUS_READY, $kitchen);
        $this->at('2026-10-02 19:10:00'); // 15 min waiting for pickup (> 10)
        $transition->serve($second->refresh(), $waiter);
        $this->settle($session, $waiter);
        $this->at('2026-10-02 21:00:00');

        $delays = $this->closeDay($restaurant, $owner)->assertCreated()->assertJsonPath('data.delays_count', 3)->json('data.report.delays');

        $this->assertSame(3, $delays['total_count']);
        $this->assertSame([
            ['stage' => 'preparation', 'excess' => 300, 'user' => 'Pablo Cocina', 'label' => 'marked_ready_by'],
            ['stage' => 'ready_pickup', 'excess' => 300, 'user' => 'Lorena Sala', 'label' => 'served_by'],
            ['stage' => 'accept', 'excess' => 120, 'user' => null, 'label' => null],
        ], array_map(fn ($item) => [
            'stage' => $item['stage'],
            'excess' => $item['excess_seconds'],
            'user' => $item['associated_action_user']['name'] ?? null,
            'label' => $item['associated_action_label'],
        ], $delays['items']));
        $this->assertSame(2100, $delays['items'][0]['duration_seconds']);
        $this->assertSame(1800, $delays['items'][0]['threshold_seconds']);
        $this->assertSame('#'.$order->id, $delays['items'][0]['order_reference']);
        $this->assertSame($table->id, $delays['items'][0]['table']['id']);
        $this->assertStringNotContainsStringIgnoringCase('culp', json_encode($delays));
    }

    public function test_delay_details_are_capped_at_20_with_the_full_count(): void
    {
        [, $owner, $restaurant] = $this->createTenant();
        $paella = $this->product($restaurant, 'Paella');
        $this->at('2026-10-02 18:00:00');
        $table = $this->createTable($restaurant);
        $this->openSession($table, $owner);
        $orders = collect(range(1, 23))->map(fn () => $this->createWaiterOrder($table, $owner, [['restaurant_product_id' => $paella->id, 'quantity' => 1]]));
        $transition = app(TransitionOrderStatusAction::class);
        $orders->each(function (Order $order, int $i) use ($transition, $owner) {
            $this->at('2026-10-02 18:'.(11 + $i).':00'); // 11..33 min to accept
            $transition->accept($order, $owner);
        });

        $delays = app(DayCloseSnapshotBuilder::class)->delays($restaurant->refresh(), CarbonImmutable::parse('2026-10-02 04:00:00', 'UTC'), CarbonImmutable::parse('2026-10-02 21:00:00', 'UTC'));

        $this->assertSame(23, $delays['total_count']);
        $this->assertCount(20, $delays['items']);
        $this->assertSame(33 * 60 - 600, $delays['items'][0]['excess_seconds']);
        $this->assertSame(14 * 60 - 600, $delays['items'][19]['excess_seconds']);
    }
}
