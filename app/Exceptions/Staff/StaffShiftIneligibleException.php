<?php

namespace App\Exceptions\Staff;

use RuntimeException;

/**
 * Raised when a staff shift is started for a User who fails
 * StaffShiftEligibility. A payload validation failure (the given user_id
 * is not a valid candidate for this Restaurant), not an authorization or
 * state failure — rendered as 422, message taken from the eligibility
 * reason. Mirrors WaiterAssignmentIneligibleException (Bloco 2). See
 * bootstrap/app.php.
 */
class StaffShiftIneligibleException extends RuntimeException {}
