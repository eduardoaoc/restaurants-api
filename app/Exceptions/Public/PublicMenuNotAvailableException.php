<?php

namespace App\Exceptions\Public;

use RuntimeException;

/**
 * Raised when the public menu endpoint is hit for a table whose restaurant
 * has no active menu. Rendered as 404 MENU_NOT_AVAILABLE — see
 * bootstrap/app.php.
 */
class PublicMenuNotAvailableException extends RuntimeException {}
