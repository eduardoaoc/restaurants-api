<?php

namespace App\Exceptions\Billing;

use RuntimeException;

/**
 * Raised when a payment is attempted (or a concurrent close is lost) on a
 * table session that is already closed. Rendered as 409
 * TABLE_SESSION_CLOSED — see bootstrap/app.php.
 */
class TableSessionClosedException extends RuntimeException {}
