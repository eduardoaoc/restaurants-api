<?php

namespace App\Exceptions\Printing;

use RuntimeException;

/**
 * Raised when a kitchen ticket is requested for an order that is not in a
 * printable status — waiting_approval (not yet accepted by the
 * restaurant) or cancelled (must never reach the kitchen). Rendered as
 * 409 ORDER_NOT_PRINTABLE — see bootstrap/app.php.
 */
class OrderNotPrintableException extends RuntimeException {}
