<?php

namespace App\Exceptions\Orders;

use RuntimeException;

/**
 * Raised when an order is attempted (public or staff) against a table with
 * no active session. Rendered as 409 TABLE_SESSION_NOT_ACTIVE — see
 * bootstrap/app.php.
 */
class TableSessionNotActiveException extends RuntimeException {}
