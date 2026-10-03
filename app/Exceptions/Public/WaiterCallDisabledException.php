<?php

namespace App\Exceptions\Public;

use RuntimeException;

/**
 * Raised when a public call-waiter request is attempted against a
 * restaurant whose RestaurantSettings::waiter_call_enabled is false.
 * Rendered as 409 WAITER_CALL_DISABLED — see bootstrap/app.php.
 */
class WaiterCallDisabledException extends RuntimeException {}
