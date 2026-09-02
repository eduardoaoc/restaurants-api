<?php

namespace App\Exceptions\Audit;

use RuntimeException;

/**
 * Raised when the audit log endpoint's ?from=/?to= query is malformed:
 * only one of the two given, an invalid calendar date, `to` before `from`,
 * or a range longer than 366 days. Rendered as 422 INVALID_AUDIT_PERIOD —
 * see bootstrap/app.php.
 */
class InvalidAuditPeriodException extends RuntimeException {}
