<?php

namespace App\Exceptions\Public;

use RuntimeException;

/**
 * Raised when a second feedback submission is attempted for a
 * TableSession that already has a CustomerFeedback row with a materially
 * different payload than the one being submitted now (an identical resend
 * replays the existing record instead — see SubmitPublicFeedbackAction).
 * Rendered as 409 FEEDBACK_ALREADY_SUBMITTED — see bootstrap/app.php.
 */
class FeedbackAlreadySubmittedException extends RuntimeException {}
