<?php

namespace App\Exceptions\Billing;

use RuntimeException;

/**
 * Raised when closing a table session that still has an order in
 * waiting_approval/confirmed/accepted/preparing/ready — unfinished
 * kitchen/approval work, closable only once every order is served or
 * cancelled. Rendered as 409 TABLE_SESSION_HAS_OPEN_ORDERS — see
 * bootstrap/app.php.
 */
class TableSessionHasOpenOrdersException extends RuntimeException {}
