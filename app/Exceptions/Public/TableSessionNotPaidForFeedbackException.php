<?php

namespace App\Exceptions\Public;

use RuntimeException;

/**
 * Raised when POST /public/feedback/{feedbackToken} is attempted against a
 * session that is not yet paid. Since the token-lifecycle fix,
 * feedback_token is generated at TableSession open (see OpenTableAction),
 * not at payment — so a client can legitimately hold a token for a still
 * unpaid visit (see PublicSessionStateResource, which surfaces it with
 * eligible=false in that state). SubmitPublicFeedbackAction is the
 * backend-authoritative gate: possession of the token alone never proves
 * eligibility, isPaid() is re-checked on every submit regardless of what
 * the client believes. Rendered as 409 TABLE_SESSION_NOT_PAID_FOR_FEEDBACK
 * — see bootstrap/app.php.
 */
class TableSessionNotPaidForFeedbackException extends RuntimeException {}
