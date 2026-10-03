<?php

namespace App\Exceptions\Printing;

use RuntimeException;

/**
 * Raised when a manual bill receipt print is attempted for a restaurant
 * whose RestaurantSettings::bill_receipt_printing_enabled is false.
 * Preview (GET) is never gated by this — only the POST /print side effect.
 * Rendered as 409 BILL_RECEIPT_PRINTING_DISABLED — see bootstrap/app.php.
 */
class BillReceiptPrintingDisabledException extends RuntimeException {}
