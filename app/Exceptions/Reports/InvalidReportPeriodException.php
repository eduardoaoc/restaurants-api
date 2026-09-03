<?php

namespace App\Exceptions\Reports;

use RuntimeException;

/**
 * Raised when a report endpoint's ?from=/?to= query is malformed: only one
 * of the two given, an invalid calendar date, `to` before `from`, or a
 * range longer than 366 days. Rendered as 422 INVALID_REPORT_PERIOD — see
 * bootstrap/app.php.
 */
class InvalidReportPeriodException extends RuntimeException {}
