<?php

use App\Actions\Invitations\AcceptInvitationAction;
use App\Exceptions\Invitations\InvitationException;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
[$mode, $token, $password] = [$argv[1], $argv[2], $argv[3]];
try {
    $result = app(AcceptInvitationAction::class)->execute($token, $mode, ['name' => 'Worker', 'password' => $password], null);
    echo json_encode(['user_id' => $result['user']->id]);
} catch (InvitationException $e) {
    echo json_encode(['code' => $e->errorCode]);
}
