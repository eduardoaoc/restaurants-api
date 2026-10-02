<?php

/**
 * Out-of-process worker for DayCloseConcurrencyTest (CARTA 9.1A). Each
 * invocation is a separate PHP process with its OWN PostgreSQL connection,
 * so advisory locks really contend. Boots the app against the testing
 * database (env passed by the test), runs one scenario and prints a JSON
 * result on stdout. Never used outside tests.
 *
 * Usage: php tests/Concurrency/worker.php <scenario> '<json args>'
 */

use App\Actions\Billing\RecordPaymentAction;
use App\Actions\Cash\RecordCashMovementAction;
use App\Actions\DayClose\CloseRestaurantDayAction;
use App\Actions\Orders\TransitionOrderStatusAction;
use App\Actions\Tables\CloseTableAction;
use App\Exceptions\DayClose\DayCloseException;
use App\Models\Order;
use App\Models\Restaurant;
use App\Models\TableSession;
use App\Models\User;
use App\Support\Restaurants\RestaurantOperationalLock;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[$scenario, $args] = [$argv[1], json_decode($argv[2] ?? '{}', true)];
$ms = fn () => (int) floor(microtime(true) * 1000);

$waitFor = function (?string $file) {
    if ($file === null) {
        return;
    }
    $deadline = microtime(true) + 15;
    while (! file_exists($file)) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException("barrier {$file} never appeared");
        }
        usleep(5000);
    }
};

$result = ['scenario' => $scenario];

try {
    switch ($scenario) {
        // An in-flight writer: takes the shared lock, records a payment,
        // signals, then keeps its transaction open for hold_ms.
        case 'hold_shared_payment':
            DB::transaction(function () use ($args, &$result, $ms) {
                $session = TableSession::query()->findOrFail($args['session_id']);
                RestaurantOperationalLock::shared($session->restaurant_id);
                $payment = app(RecordPaymentAction::class)->execute($session, User::query()->findOrFail($args['user_id']), ['method' => 'cash', 'amount' => $args['amount']])['payment'];
                $result['payment_id'] = $payment->id;
                $result['recorded_at'] = $payment->recorded_at->format('Y-m-d H:i:s');
                file_put_contents($args['signal_file'], '1');
                usleep($args['hold_ms'] * 1000);
                $result['committing_at_ms'] = $ms();
            });
            break;

        case 'hold_shared_pay_and_close':
            DB::transaction(function () use ($args, &$result, $ms) {
                $session = TableSession::query()->findOrFail($args['session_id']);
                $user = User::query()->findOrFail($args['user_id']);
                RestaurantOperationalLock::shared($session->restaurant_id);
                $payment = app(RecordPaymentAction::class)->execute($session, $user, ['method' => 'cash', 'amount' => $args['amount']])['payment'];
                app(CloseTableAction::class)->execute($session->refresh(), $user);
                $result['payment_id'] = $payment->id;
                $result['recorded_at'] = $payment->recorded_at->format('Y-m-d H:i:s');
                file_put_contents($args['signal_file'], '1');
                usleep($args['hold_ms'] * 1000);
                $result['committing_at_ms'] = $ms();
            });
            break;

        case 'cash_movement':
            $result['started_at_ms'] = $ms();
            $movement = app(RecordCashMovementAction::class)->execute(Restaurant::query()->findOrFail($args['restaurant_id']), User::query()->findOrFail($args['user_id']), [
                'type' => 'pay_in', 'amount' => $args['amount'], 'reason' => 'concurrency', 'idempotency_key' => $args['key'],
            ])['movement'];
            $result['finished_at_ms'] = $ms();
            $result['movement_id'] = $movement->id;
            break;

        case 'payment':
            $waitFor($args['barrier'] ?? null);
            $result['started_at_ms'] = $ms();
            $payment = app(RecordPaymentAction::class)->execute(TableSession::query()->findOrFail($args['session_id']), User::query()->findOrFail($args['user_id']), ['method' => 'cash', 'amount' => $args['amount']])['payment'];
            $result['finished_at_ms'] = $ms();
            $result['payment_id'] = $payment->id;
            $result['recorded_at'] = $payment->recorded_at->format('Y-m-d H:i:s');
            break;

        case 'transition':
            $waitFor($args['barrier'] ?? null);
            $result['started_at_ms'] = $ms();
            $order = app(TransitionOrderStatusAction::class)->{$args['method']}(Order::query()->findOrFail($args['order_id']), User::query()->findOrFail($args['user_id']));
            $result['finished_at_ms'] = $ms();
            $result['status'] = $order->status;
            break;

        case 'close':
            $waitFor($args['barrier'] ?? null);
            $close = app(CloseRestaurantDayAction::class)->execute(Restaurant::query()->findOrFail($args['restaurant_id']), User::query()->findOrFail($args['user_id']), $args['payload']);
            $result['outcome'] = $close['replayed'] ? 'replayed' : 'created';
            $result['day_close_id'] = $close['day_close']->id;
            break;

            // Mixed writers + closes hammering one restaurant for duration_ms;
            // every exception is reported (a deadlock would be SQLSTATE 40P01).
        case 'stress':
            $waitFor($args['barrier'] ?? null);
            $deadline = microtime(true) + $args['duration_ms'] / 1000;
            $result['operations'] = 0;
            $result['errors'] = [];
            while (microtime(true) < $deadline) {
                try {
                    if ($args['kind'] === 'close') {
                        app(CloseRestaurantDayAction::class)->execute(Restaurant::query()->findOrFail($args['restaurant_id']), User::query()->findOrFail($args['user_id']), [
                            ...$args['payload'], 'idempotency_key' => uniqid('stress-', true),
                        ]);
                    } else {
                        DB::transaction(function () use ($args) {
                            $session = TableSession::query()->findOrFail($args['session_id']);
                            RestaurantOperationalLock::shared($session->restaurant_id);
                            TableSession::query()->whereKey($session->id)->lockForUpdate()->first();
                            usleep(2000);
                        });
                    }
                    $result['operations']++;
                } catch (DayCloseException $e) {
                    $result['operations']++; // expected domain refusal (blocked)
                } catch (Throwable $e) {
                    $result['errors'][] = get_class($e).': '.$e->getMessage();
                }
            }
            break;

        default:
            throw new InvalidArgumentException("unknown scenario {$scenario}");
    }
} catch (DayCloseException $e) {
    $result['outcome'] = 'error';
    $result['error_code'] = $e->errorCode;
} catch (Throwable $e) {
    $result['outcome'] = 'exception';
    $result['exception'] = get_class($e).': '.$e->getMessage();
}

echo json_encode($result);
