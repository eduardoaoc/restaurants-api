<?php

namespace App\Exceptions\TableRequests;

use RuntimeException;

/**
 * Raised when a table session already has an open (pending/acknowledged)
 * request of the same type — the application-level mirror of the partial
 * unique index on table_requests (table_session_id, type) WHERE status IN
 * ('pending','acknowledged'), which is the real guard against a genuine
 * double-submit race. Rendered as 409 TABLE_REQUEST_ALREADY_OPEN — see
 * bootstrap/app.php.
 */
class TableRequestAlreadyOpenException extends RuntimeException {}
