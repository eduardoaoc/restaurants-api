<?php

namespace App\Exceptions\Orders;

use RuntimeException;

/**
 * Raised when approve/reject is attempted on an order that is not an
 * eligible waiting_approval customer_qr order (already confirmed/cancelled,
 * or not a customer_qr order at all). Rendered as a plain 409 `{message}`
 * — the same convention as the existing TableSessionConflictException —
 * since this is an authenticated-only, admin-facing conflict, not part of
 * the public error contract. See bootstrap/app.php.
 */
class OrderStateConflictException extends RuntimeException {}
