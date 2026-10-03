<?php

namespace App\Exceptions\Staff;

use RuntimeException;

/**
 * Raised when a staff shift operation conflicts with its current state:
 * starting a shift for a user who already has an active one at that
 * Restaurant, or ending a shift that has already ended. Rendered as 409 —
 * see bootstrap/app.php. Mirrors TableSessionConflictException's role for
 * TableSession open/close.
 */
class StaffShiftConflictException extends RuntimeException {}
