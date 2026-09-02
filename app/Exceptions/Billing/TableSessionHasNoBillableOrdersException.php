<?php

namespace App\Exceptions\Billing;

use RuntimeException;

/**
 * Raised when a payment or close is attempted on a table session with no
 * billable order at all (none, or only cancelled/waiting_approval ones) —
 * there is nothing to charge or to close out. Rendered as 409
 * TABLE_SESSION_HAS_NO_BILLABLE_ORDERS — see bootstrap/app.php.
 */
class TableSessionHasNoBillableOrdersException extends RuntimeException {}
