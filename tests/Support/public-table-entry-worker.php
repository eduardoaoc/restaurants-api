<?php

use App\Actions\Public\EnterPublicTableAction;
use App\Actions\Tables\OpenTableAction;
use App\Exceptions\TableSessionConflictException;
use App\Models\Table;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;

// Standalone process: same application/database as the test, own connection.
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
try {
    if ($argv[2] === 'staff') {
        $table = Table::query()->where('public_token', $argv[1])->firstOrFail();
        $owner = User::query()->findOrFail((int) $argv[3]);
        $id = app(OpenTableAction::class)->execute($table, $owner, 2)->id;
    } else {
        $id = app(EnterPublicTableAction::class)->execute($argv[1], null)->activeSession->id;
    }
    echo json_encode(['id' => $id]);
} catch (TableSessionConflictException) {
    echo json_encode(['conflict' => true]);
}
