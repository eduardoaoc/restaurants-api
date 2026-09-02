<?php

namespace App\Exceptions\Billing;

use RuntimeException;

/**
 * Raised when a table session's bill is already fully paid: no new
 * payment can be recorded (the financial total is frozen), and no new
 * order or table request can be created against it either — see
 * OrderCreationService and CreatePublicTableRequestAction, which both
 * throw this same class. Rendered as 409 TABLE_SESSION_ALREADY_PAID —
 * see bootstrap/app.php.
 */
class TableSessionAlreadyPaidException extends RuntimeException {}
