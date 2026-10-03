<?php

namespace Tests\Concerns;

use App\Actions\Feedback\SubmitPublicFeedbackAction;
use App\Models\CustomerFeedback;
use App\Models\TableSession;

/**
 * CustomerFeedback deliberately has no factory, same as Order/TableSession/
 * PaymentRecord: its rows must satisfy cross-model invariants (restaurant/
 * organization/session coherence, the one-per-session uniqueness) that a
 * blind factory can't safely fake. This helper goes through the real
 * Action instead.
 */
trait InteractsWithCustomerFeedback
{
    /**
     * @param  array<string, mixed>  $overrides
     * @return array{feedback: CustomerFeedback, replayed: bool}
     */
    protected function submitFeedback(TableSession $session, array $overrides = []): array
    {
        return app(SubmitPublicFeedbackAction::class)->execute(
            $session->feedback_token,
            array_merge($this->defaultFeedbackPayload(), $overrides),
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultFeedbackPayload(): array
    {
        return [
            'first_name' => 'Ana',
            'last_name' => 'García',
            'wait_time_rating' => 4,
            'food_rating' => 5,
            'service_rating' => 5,
            'overall_rating' => 5,
            'experience_comment' => 'Great evening.',
            'improvement_comment' => 'Faster drinks next time.',
            'contact' => 'ana@example.com',
        ];
    }
}
