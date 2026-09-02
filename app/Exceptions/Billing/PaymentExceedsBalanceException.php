<?php

namespace App\Exceptions\Billing;

use RuntimeException;

/**
 * Raised when a payment amount exceeds the session's current balance.
 * Overpayment is never allowed in this MVP — change/refund isn't
 * modeled. Rendered as 422 PAYMENT_EXCEEDS_BALANCE — see
 * bootstrap/app.php.
 */
class PaymentExceedsBalanceException extends RuntimeException {}
