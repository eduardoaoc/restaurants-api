<?php

namespace App\Http\Resources\Api\V1\Public;

use App\Models\TableSession;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use stdClass;

/**
 * Public projection of a table's session state. Wraps a nullable
 * TableSession (already scoped to non-closed statuses by the caller) and
 * never exposes its identity — only whether it is active, and its status.
 *
 * feedback (Passo 3.5 §2, revised by the token-lifecycle fix): the visit's
 * feedback_token is generated at session-open time (see OpenTableAction),
 * not at payment, so it is surfaced here for the WHOLE lifetime of the
 * table's active session — unpaid or paid — letting the QR client persist
 * it well before any payment happens, closing the race where a fast
 * payment->close leaves no polling window to capture it. `eligible` alone
 * gates whether the visit can actually be reviewed yet (mirrors the
 * session's payment_status — backend-authoritative, re-checked again by
 * SubmitPublicFeedbackAction regardless of what the client sends).
 *
 * Deliberately never reconstructed for a session that has since closed:
 * this endpoint is keyed by the table's reusable public_token, so once the
 * session closes a different guest may scan the same physical QR next —
 * exposing a past visit's feedback token here would leak it to them. Once
 * a client has captured the token during the active window, the dedicated
 * /public/feedback/{feedbackToken} endpoints keep working after close on
 * their own (they check the specific session's isPaid(), not the table's
 * current state) — no other discovery path is needed.
 */
class PublicSessionStateResource extends JsonResource
{
    /**
     * A non-null placeholder is passed to the parent constructor even when
     * there is no session: JsonResource::filter() collapses any nested
     * resource whose ->resource is null to a bare `null`, which would break
     * the stable `{active, status}` contract required when there is no
     * active session.
     */
    public function __construct(private readonly ?TableSession $session)
    {
        parent::__construct($session ?? new stdClass);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'active' => $this->session !== null,
            'status' => $this->session?->status,
            'feedback' => $this->feedback(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function feedback(): array
    {
        if ($this->session === null) {
            return ['eligible' => false];
        }

        return [
            'eligible' => $this->session->isPaid(),
            // ensureFeedbackToken() lazily backfills a session that
            // predates open-time generation (compatibility — see the
            // method docblock); a no-op for every session opened after
            // this fix, which already has one from OpenTableAction.
            'token' => $this->session->ensureFeedbackToken(),
            'already_submitted' => $this->session->hasSubmittedFeedback(),
        ];
    }
}
