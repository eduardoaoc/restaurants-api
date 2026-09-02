<?php

namespace App\Exceptions\Billing;

use RuntimeException;

/**
 * Raised when an Idempotency-Key on the payment endpoint was already used
 * for that table session with a different payload. Rendered as 409
 * PAYMENT_IDEMPOTENCY_KEY_REUSED — see bootstrap/app.php.
 */
class PaymentIdempotencyKeyReusedException extends RuntimeException {}
