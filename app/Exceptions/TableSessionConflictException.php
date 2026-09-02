<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Raised when a table session operation conflicts with the table's current
 * state (opening a table that already has an active session, or closing a
 * table that has none). Rendered as a 409 Conflict — see bootstrap/app.php.
 */
class TableSessionConflictException extends RuntimeException {}
