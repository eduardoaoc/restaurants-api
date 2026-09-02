<?php

namespace App\Actions\Orders;

use App\Exceptions\TableSessionConflictException;
use App\Models\Order;
use App\Models\Table;
use App\Models\User;
use App\Support\Locale\LocaleResolver;

/**
 * Creates an order launched by a waiter for a table. Requires an active
 * TableSession just like the public flow, but is authenticated: the order
 * is created already `confirmed`, since the waiter placing it does not
 * need to approve their own order.
 *
 * Uses the existing TableSessionConflictException (409, plain `{message}`)
 * for "no active session" rather than the public TABLE_SESSION_NOT_ACTIVE
 * contract — this is an authenticated, admin-facing endpoint, so it
 * follows the same conflict convention already used by open/close table.
 */
class CreateStaffOrderAction
{
    public function __construct(private readonly OrderCreationService $orderCreationService) {}

    /**
     * @param  array{locale?: ?string, note?: ?string, items: array<int, array<string, mixed>>}  $data
     */
    public function execute(Table $table, User $waiter, array $data): Order
    {
        $session = $table->activeSession;

        if (! $session) {
            throw new TableSessionConflictException('This table has no active session.');
        }

        $locale = isset($data['locale']) ? LocaleResolver::normalize($data['locale']) : 'es';

        return $this->orderCreationService->execute(
            table: $table,
            tableSessionId: $session->id,
            origin: Order::ORIGIN_WAITER,
            createdBy: $waiter,
            locale: $locale,
            items: $data['items'],
            customerNote: $data['note'] ?? null,
        );
    }
}
