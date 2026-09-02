<?php

namespace App\Exceptions\Staff;

use RuntimeException;

/**
 * Raised when a user attempts to create a StaffReview targeting
 * themselves — never allowed, even for an owner/manager who otherwise
 * holds manage_staff_reviews. Rendered as 422 CANNOT_REVIEW_SELF — see
 * bootstrap/app.php.
 */
class CannotReviewSelfException extends RuntimeException {}
