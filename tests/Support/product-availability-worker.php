<?php

use App\Actions\Catalog\UpdateRestaurantProductAction;
use App\Actions\Orders\CreatePublicOrderAction;
use App\Actions\Orders\CreateStaffOrderAction;
use App\Exceptions\Orders\InvalidOrderItemException;
use App\Models\RestaurantProduct;
use App\Models\Table;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$table = Table::findOrFail((int) $argv[2]);
$rp = RestaurantProduct::findOrFail((int) $argv[3]);
$user = User::findOrFail((int) $argv[4]);
$payload = ['items' => [['restaurant_product_id' => $rp->id, 'quantity' => 1]]];
try {
    if ($argv[1] === 'sell') {
        app(UpdateRestaurantProductAction::class)->execute($rp, $user, ['available' => false]);
        echo json_encode(['sold' => true]);
    } elseif ($argv[1] === 'qr') {
        $payload['idempotency_key'] = 'concurrent-replay';
        $result = app(CreatePublicOrderAction::class)->execute($table->public_token, $payload, 'en', $table->activeSession->feedback_token);
        echo json_encode(['id' => $result['order']->id, 'replayed' => $result['replayed']]);
    } else {
        $order = app(CreateStaffOrderAction::class)->execute($table, $user, $payload);
        echo json_encode(['id' => $order->id]);
    }
} catch (InvalidOrderItemException) {
    echo json_encode(['code' => 'INVALID_ORDER_ITEM']);
}
