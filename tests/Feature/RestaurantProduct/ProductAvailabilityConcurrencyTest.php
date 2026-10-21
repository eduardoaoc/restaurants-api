<?php

namespace Tests\Feature\RestaurantProduct;

use App\Actions\Catalog\UpdateRestaurantProductAction;
use App\Actions\Orders\CreatePublicOrderAction;
use App\Events\Realtime\ProductAvailabilityChanged;
use App\Models\Order;
use App\Models\RestaurantProduct;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\InteractsWithOrders;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class ProductAvailabilityConcurrencyTest extends TestCase
{
    use DatabaseTruncation, InteractsWithOrders, InteractsWithTenants;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRolesAndPermissions();
        $this->beforeApplicationDestroyed(fn () => $this->truncateTablesForAllConnections());
    }

    private function worker(string $mode, $table, $rp, $owner): array
    {
        $process = proc_open([PHP_BINARY, base_path('tests/Support/product-availability-worker.php'), $mode, (string) $table->id, (string) $rp->id, (string) $owner->id], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
        $this->assertIsResource($process);
        return [$process, $pipes];
    }

    private function assertWorkerWaiting(): void
    {
        $deadline = microtime(true) + 10;
        do {
            DB::select('SELECT pg_stat_clear_snapshot()');
            $waiting = DB::table('pg_stat_activity')->where('datname', DB::connection()->getDatabaseName())->where('wait_event_type', 'Lock')->count();
            if ($waiting > 0) break;
            usleep(20000);
        } while (microtime(true) < $deadline);
        $this->assertGreaterThan(0, $waiting, 'Independent worker must actually wait on PostgreSQL');
    }

    private function workerResult(array $worker): array
    {
        [$process, $pipes] = $worker;
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        $this->assertSame(0, proc_close($process), $err);
        return json_decode($out, true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_sell_first_blocks_a_real_overlapping_new_order(): void
    {
        [, $owner, $r, $rp] = $this->createTenantWithRestaurantProduct();
        $table = $this->createTable($r);
        $this->openSession($table, $owner);
        DB::beginTransaction();
        try {
            app(UpdateRestaurantProductAction::class)->execute($rp, $owner, ['available' => false]);
            $worker = $this->worker('staff', $table, $rp, $owner);
            $this->assertWorkerWaiting();
        } finally { DB::commit(); }
        $this->assertSame(['code' => 'INVALID_ORDER_ITEM'], $this->workerResult($worker));
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_items', 0);
    }

    public function test_order_first_commits_snapshot_then_overlapping_sell_completes(): void
    {
        [, $owner, $r, $rp] = $this->createTenantWithRestaurantProduct();
        $table = $this->createTable($r);
        $this->openSession($table, $owner);
        DB::beginTransaction();
        try {
            $order = $this->createWaiterOrder($table, $owner, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);
            $worker = $this->worker('sell', $table, $rp, $owner);
            $this->assertWorkerWaiting();
        } finally { DB::commit(); }
        $this->assertSame(['sold' => true], $this->workerResult($worker));
        $this->assertFalse($rp->fresh()->available);
        $this->assertSame('10.00', $order->fresh()->total);
        $this->assertSame('10.00', $order->items()->first()->unit_price_snapshot);
    }

    public function test_overlapping_same_key_replays_even_when_winner_also_sells_out_before_commit(): void
    {
        [, $owner, $r, $rp] = $this->createTenantWithRestaurantProduct();
        $table = $this->createTable($r);
        $session = $this->openSession($table, $owner);
        DB::beginTransaction();
        try {
            $result = app(CreatePublicOrderAction::class)->execute($table->public_token, ['items' => [['restaurant_product_id' => $rp->id, 'quantity' => 1]], 'idempotency_key' => 'concurrent-replay'], 'en', $session->feedback_token);
            $worker = $this->worker('qr', $table, $rp, $owner);
            $this->assertWorkerWaiting();
            app(UpdateRestaurantProductAction::class)->execute($rp, $owner, ['available' => false]);
        } finally { DB::commit(); }
        $this->assertSame(['id' => $result['order']->id, 'replayed' => true], $this->workerResult($worker));
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_real_dispatcher_defers_until_commit_and_rollback_leaves_no_event_or_activity(): void
    {
        [, $owner, , $rp] = $this->createTenantWithRestaurantProduct();
        $events = [];
        Event::listen(ProductAvailabilityChanged::class, function ($event) use (&$events) { $events[] = $event; });
        $baseline = DB::table('restaurant_activity_events')->count();
        DB::beginTransaction();
        app(UpdateRestaurantProductAction::class)->execute($rp, $owner, ['available' => false]);
        $this->assertCount(0, $events);
        DB::rollBack();
        $this->assertCount(0, $events);
        $this->assertTrue($rp->fresh()->available);
        $this->assertSame($baseline, DB::table('restaurant_activity_events')->count());
        app(UpdateRestaurantProductAction::class)->execute($rp, $owner, ['available' => false]);
        $this->assertCount(1, $events);
        app(UpdateRestaurantProductAction::class)->execute($rp, $owner, ['available' => false]);
        $this->assertCount(1, $events);
        $this->assertSame('private-restaurant.'.$rp->restaurant_id, $events[0]->broadcastOn()[0]->name);
        $this->assertSame(['event_id', 'schema_version', 'occurred_at', 'restaurant_id', 'restaurant_product_id'], array_keys($events[0]->broadcastWith()));
    }
}
