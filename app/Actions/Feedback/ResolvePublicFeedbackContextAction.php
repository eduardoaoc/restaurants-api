<?php

namespace App\Actions\Feedback;

use App\Exceptions\Public\FeedbackTokenNotFoundException;
use App\Models\TableSession;

/**
 * Resolves a public feedback_token to its TableSession — the only lookup
 * key the public feedback endpoints accept (never table_session_id,
 * table_id, or the table's own public_token; see Passo 3.5 §6). A token
 * that does not exist (never issued, or guessed) is indistinguishable
 * from any other invalid token: both are a plain 404.
 */
class ResolvePublicFeedbackContextAction
{
    public function execute(string $feedbackToken): TableSession
    {
        $session = TableSession::query()
            ->where('feedback_token', $feedbackToken)
            ->with('restaurant', 'table')
            ->first();

        if (! $session) {
            throw new FeedbackTokenNotFoundException;
        }

        return $session;
    }
}
