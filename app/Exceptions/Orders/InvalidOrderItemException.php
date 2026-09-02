<?php

namespace App\Exceptions\Orders;

use RuntimeException;

/**
 * Raised when an order item references a RestaurantProduct that does not
 * resolve for the order's restaurant (wrong restaurant, unavailable,
 * inactive product, or no resolvable translation). Never distinguishes the
 * exact cause to the client. Rendered as 422 INVALID_ORDER_ITEM — see
 * bootstrap/app.php.
 */
class InvalidOrderItemException extends RuntimeException {}
