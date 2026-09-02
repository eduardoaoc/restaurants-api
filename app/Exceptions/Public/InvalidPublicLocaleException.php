<?php

namespace App\Exceptions\Public;

use RuntimeException;

/**
 * Raised when a `?locale=` query value does not match the expected
 * language[-REGION] format. Rendered as 422 INVALID_LOCALE — see
 * bootstrap/app.php.
 */
class InvalidPublicLocaleException extends RuntimeException {}
