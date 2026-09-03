<?php

namespace App\Exceptions\Public;

use RuntimeException;

/**
 * Raised when a public order is attempted against a restaurant whose
 * RestaurantSettings::customer_ordering_enabled is false. Rendered as 409
 * CUSTOMER_ORDERING_DISABLED — see bootstrap/app.php.
 */
class CustomerOrderingDisabledException extends RuntimeException {}
