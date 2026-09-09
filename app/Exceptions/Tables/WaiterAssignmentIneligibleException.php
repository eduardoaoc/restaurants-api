<?php

namespace App\Exceptions\Tables;

use RuntimeException;

/**
 * Raised when a waiter assignment/reassignment targets a User who fails
 * WaiterAssignmentEligibility. This is a payload validation failure (the
 * given user_id is not a valid candidate for this operation), not an
 * authorization or session-state failure — rendered as 422, message taken
 * from the eligibility reason. See bootstrap/app.php.
 */
class WaiterAssignmentIneligibleException extends RuntimeException {}
