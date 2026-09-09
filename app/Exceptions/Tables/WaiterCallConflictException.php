<?php

namespace App\Exceptions\Tables;

use RuntimeException;

/**
 * Raised when a WaiterCall operation conflicts with its current state: a
 * new call attempted while the session already has a pending one, or an
 * already-acknowledged call being acknowledged again. Rendered as 409,
 * dynamic message — see bootstrap/app.php.
 */
class WaiterCallConflictException extends RuntimeException {}
