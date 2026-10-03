<?php

namespace App\Exceptions\Public;

use RuntimeException;

/**
 * Raised when GET /public/visits/{feedbackToken} resolves a session that is
 * not yet paid. feedback_token exists from session open (see
 * OpenTableAction), so a client can hold it for a still-unpaid visit; the
 * visit summary is a post-payment projection, and isPaid() is re-checked on
 * every read. Deliberately separate from
 * TableSessionNotPaidForFeedbackException: that code/message is part of the
 * feedback contract ("not eligible for feedback yet") and would misdescribe
 * this endpoint. Rendered as 409 TABLE_SESSION_NOT_PAID_FOR_VISIT — see
 * bootstrap/app.php.
 */
class TableSessionNotPaidForVisitException extends RuntimeException {}
