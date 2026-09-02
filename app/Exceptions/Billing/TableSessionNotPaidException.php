<?php

namespace App\Exceptions\Billing;

use RuntimeException;

/**
 * Raised when closing a table session whose bill is not (or, on defensive
 * recheck, no longer provably) fully paid. Rendered as 409
 * TABLE_SESSION_NOT_PAID — see bootstrap/app.php.
 */
class TableSessionNotPaidException extends RuntimeException {}
