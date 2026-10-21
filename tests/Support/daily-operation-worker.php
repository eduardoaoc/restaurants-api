<?php

use App\Actions\DailyOperation\OpenDailyOperationAction;
use App\Actions\Tables\OpenTableAction;
use App\Exceptions\DailyOperation\DailyOperationException;
use App\Models\Restaurant;
use App\Models\Table;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
[$mode, $restaurantId, $userId, $arg] = [$argv[1], (int) $argv[2], (int) $argv[3], $argv[4] ?? null];
$user = User::findOrFail($userId);
try {
    if ($mode === 'open-table') {
        $session = app(OpenTableAction::class)->execute(Table::findOrFail((int) $arg), $user, 2);
        echo json_encode(['session_id' => $session->id, 'daily_operation_id' => $session->daily_operation_id]);
    } else {
        $result = app(OpenDailyOperationAction::class)->execute(Restaurant::with('settings')->findOrFail($restaurantId), $user, ['opening_float' => '10.00', 'idempotency_key' => $arg]);
        echo json_encode(['id' => $result['daily_operation']->public_id, 'replayed' => $result['replayed']]);
    }
} catch (DailyOperationException $e) {
    echo json_encode(['code' => $e->errorCode]);
}
