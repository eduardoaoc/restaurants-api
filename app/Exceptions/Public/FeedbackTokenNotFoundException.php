<?php

namespace App\Exceptions\Public;

use RuntimeException;

/**
 * Raised when a feedback_token does not resolve to any TableSession —
 * either it was never issued (the visit never reached paid) or it is
 * simply invalid/guessed. Deliberately the same 404 shape regardless of
 * which of those is true, so the public endpoint never distinguishes
 * "this visit isn't paid yet" from "this token doesn't exist" for an
 * unauthenticated caller. Rendered as 404 — see bootstrap/app.php.
 */
class FeedbackTokenNotFoundException extends RuntimeException {}
