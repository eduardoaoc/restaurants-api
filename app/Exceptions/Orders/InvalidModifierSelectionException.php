<?php

namespace App\Exceptions\Orders;

use RuntimeException;

/**
 * Raised when a submitted modifier_option_ids selection is invalid:
 * duplicate ids, an option outside the item's RestaurantProduct, an
 * inactive/unavailable option or group, or a selection count outside
 * [min_select, max_select]. Rendered as 422 INVALID_MODIFIER_SELECTION —
 * see bootstrap/app.php.
 */
class InvalidModifierSelectionException extends RuntimeException {}
