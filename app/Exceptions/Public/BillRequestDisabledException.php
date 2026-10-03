<?php

namespace App\Exceptions\Public;

use RuntimeException;

/**
 * Raised when a public request-bill request is attempted against a
 * restaurant whose RestaurantSettings::bill_request_enabled is false.
 * Rendered as 409 BILL_REQUEST_DISABLED — see bootstrap/app.php.
 */
class BillRequestDisabledException extends RuntimeException {}
