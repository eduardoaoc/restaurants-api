<?php

namespace App\Exceptions\TableRequests;

use RuntimeException;

/**
 * Raised when acknowledge/complete/cancel is attempted on a table request
 * that is not in an eligible starting state. Rendered as a plain 409
 * `{message}` — the same convention as OrderStateConflictException — since
 * this is an authenticated-only, admin-facing conflict, not part of the
 * public error contract. See bootstrap/app.php.
 */
class TableRequestStateConflictException extends RuntimeException {}
