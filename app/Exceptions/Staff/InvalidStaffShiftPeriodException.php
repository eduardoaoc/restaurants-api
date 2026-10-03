<?php

namespace App\Exceptions\Staff;

use RuntimeException;

/**
 * Raised when the staff shifts list endpoint's ?from=/?to= query is
 * malformed: only one of the two given, an invalid calendar date, `to`
 * before `from`, or a range longer than 366 days. Rendered as 422
 * INVALID_STAFF_SHIFT_PERIOD — see bootstrap/app.php.
 */
class InvalidStaffShiftPeriodException extends RuntimeException {}
