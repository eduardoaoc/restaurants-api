<?php

namespace App\Exceptions\Analytics;

use RuntimeException;

/**
 * Raised when the Analytics endpoint's ?from=/?to=/?granularity= query is
 * malformed: only one of from/to given, an invalid calendar date, `to`
 * before `from`, a range longer than 366 days, or an unsupported
 * granularity. Rendered as 422 INVALID_ANALYTICS_PERIOD — see
 * bootstrap/app.php. A standalone exception, not a reuse of
 * InvalidReportPeriodException, matching this project's existing
 * convention of one period resolver + exception per endpoint family (see
 * AuditLogPeriodResolver/PerformancePeriodResolver/ReportPeriodResolver).
 */
class InvalidAnalyticsPeriodException extends RuntimeException {}
