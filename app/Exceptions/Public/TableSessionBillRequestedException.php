<?php

namespace App\Exceptions\Public;

use RuntimeException;

/**
 * Raised when a public (customer_qr) order is attempted against a
 * TableSession that has an open request_bill TableRequest (status pending
 * or acknowledged — see TableRequest::openStatuses()). Once the customer
 * has asked for the bill, the QR surface stops accepting new orders for
 * that session; staff ordering is unaffected (see
 * OrderCreationService::$blockIfBillRequested). Rendered as 409
 * TABLE_SESSION_BILL_REQUESTED — see bootstrap/app.php.
 */
class TableSessionBillRequestedException extends RuntimeException {}
