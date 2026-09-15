<?php

namespace App\Actions\Feedback;

use App\Exceptions\Public\FeedbackAlreadySubmittedException;
use App\Exceptions\Public\FeedbackTokenNotFoundException;
use App\Exceptions\Public\TableSessionNotPaidForFeedbackException;
use App\Models\AuditLog;
use App\Models\CustomerFeedback;
use App\Models\TableSession;
use App\Support\Audit\AuditLogger;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Records a customer's post-visit feedback against the TableSession a
 * feedback_token points to. One feedback per visit (Passo 3.5 §4),
 * enforced by a unique constraint on customer_feedbacks.table_session_id
 * — this action never trusts a "does one already exist" check alone to
 * prevent a race between two concurrent submissions for the same token.
 *
 * waiter_id is captured from TableSession::assigned_waiter_user_id AT THIS
 * MOMENT and stored as a plain column — a later reassignment on the
 * session can never rewrite it (§9).
 *
 * Never touches Order/PaymentRecord/TableSession.status|payment_status —
 * feedback is purely post-visit information (§19 — must not affect the
 * billing/closing flow in any way).
 */
class SubmitPublicFeedbackAction
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * @param  array{first_name: string, last_name: string, wait_time_rating: int, food_rating: int, service_rating: int, overall_rating: int, experience_comment?: ?string, improvement_comment?: ?string, contact?: ?string}  $data
     * @return array{feedback: CustomerFeedback, replayed: bool}
     */
    public function execute(string $feedbackToken, array $data): array
    {
        return DB::transaction(function () use ($feedbackToken, $data) {
            $session = TableSession::query()->where('feedback_token', $feedbackToken)->lockForUpdate()->first();

            if (! $session) {
                throw new FeedbackTokenNotFoundException;
            }

            // Authoritative eligibility gate (see
            // TableSessionNotPaidForFeedbackException docblock): the token
            // is minted at session-open, well before payment, so a client
            // can legitimately hold one for a still-unpaid visit — this
            // check, not token possession, is what actually gates submit.
            if (! $session->isPaid()) {
                throw new TableSessionNotPaidForFeedbackException;
            }

            $existing = CustomerFeedback::query()->where('table_session_id', $session->id)->first();

            if ($existing) {
                return $this->replayOrConflict($existing, $data);
            }

            try {
                $feedback = CustomerFeedback::query()->create([
                    'organization_id' => $session->restaurant->organization_id,
                    'restaurant_id' => $session->restaurant_id,
                    'table_session_id' => $session->id,
                    'waiter_id' => $session->assigned_waiter_user_id,
                    'first_name' => $data['first_name'],
                    'last_name' => $data['last_name'],
                    'wait_time_rating' => $data['wait_time_rating'],
                    'food_rating' => $data['food_rating'],
                    'service_rating' => $data['service_rating'],
                    'overall_rating' => $data['overall_rating'],
                    'experience_comment' => $data['experience_comment'] ?? null,
                    'improvement_comment' => $data['improvement_comment'] ?? null,
                    'contact' => $data['contact'] ?? null,
                    'submitted_at' => now(),
                ]);
            } catch (UniqueConstraintViolationException $e) {
                // Lost a race against another request for the same session.
                $existing = CustomerFeedback::query()->where('table_session_id', $session->id)->first();

                if (! $existing) {
                    throw $e;
                }

                return $this->replayOrConflict($existing, $data);
            }

            $this->auditLogger->log(
                organizationId: $session->restaurant->organization_id,
                restaurantId: $session->restaurant_id,
                actorType: AuditLog::ACTOR_PUBLIC,
                actor: null,
                event: AuditLog::EVENT_CUSTOMER_FEEDBACK_CREATED,
                resourceType: AuditLog::RESOURCE_CUSTOMER_FEEDBACK,
                resourceId: $feedback->id,
                metadata: [
                    'table_session_id' => $session->id,
                    'overall_rating' => $feedback->overall_rating,
                ],
            );

            return ['feedback' => $feedback, 'replayed' => false];
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{feedback: CustomerFeedback, replayed: bool}
     */
    private function replayOrConflict(CustomerFeedback $existing, array $data): array
    {
        $matches = $existing->first_name === $data['first_name']
            && $existing->last_name === $data['last_name']
            && $existing->wait_time_rating === $data['wait_time_rating']
            && $existing->food_rating === $data['food_rating']
            && $existing->service_rating === $data['service_rating']
            && $existing->overall_rating === $data['overall_rating']
            && $existing->experience_comment === ($data['experience_comment'] ?? null)
            && $existing->improvement_comment === ($data['improvement_comment'] ?? null)
            && $existing->contact === ($data['contact'] ?? null);

        if (! $matches) {
            throw new FeedbackAlreadySubmittedException;
        }

        return ['feedback' => $existing, 'replayed' => true];
    }
}
