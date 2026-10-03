<?php

namespace App\Actions\Public;

use App\Actions\Feedback\ResolvePublicFeedbackContextAction;
use App\Exceptions\Public\TableSessionNotPaidForVisitException;
use App\Models\Order;
use App\Models\TableSession;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Resolves a feedback_token to its paid TableSession for the public
 * post-payment visit summary (CARTA 5.1A). The token is the only key
 * accepted — resolution (and its 404 for unknown tokens) is delegated to
 * ResolvePublicFeedbackContextAction so both public contracts share one
 * definition of "valid token". Works for active and closed sessions alike:
 * closing a table never revokes access to its own visit.
 *
 * Only billable orders (Order::billableStatuses()) are loaded, with their
 * items and modifiers eager-loaded in a fixed number of queries — the
 * snapshot columns are what gets rendered, never the current catalog.
 */
class ResolvePublicVisitAction
{
    public function __construct(private readonly ResolvePublicFeedbackContextAction $resolveContext) {}

    public function execute(string $feedbackToken): TableSession
    {
        $session = $this->resolveContext->execute($feedbackToken);

        if (! $session->isPaid()) {
            throw new TableSessionNotPaidForVisitException;
        }

        return $session->load([
            'restaurant.settings',
            'orders' => fn (HasMany $query) => $query
                ->whereIn('status', Order::billableStatuses())
                ->orderBy('created_at')
                ->orderBy('id'),
            'orders.items' => fn (HasMany $query) => $query->orderBy('id'),
            'orders.items.modifiers' => fn (HasMany $query) => $query->orderBy('id'),
        ]);
    }
}
