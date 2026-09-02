<?php

namespace App\Exceptions\Orders;

use RuntimeException;

/**
 * Raised when an Idempotency-Key on the public order-create endpoint was
 * already used for that table session with a different payload. Rendered
 * as 409 IDEMPOTENCY_KEY_REUSED — see bootstrap/app.php.
 */
class IdempotencyKeyReusedException extends RuntimeException {}
