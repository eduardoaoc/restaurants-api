<?php

namespace App\Actions\Orders;

use App\Actions\Public\ResolvePublicTableAction;
use App\Exceptions\Orders\IdempotencyKeyReusedException;
use App\Exceptions\Orders\TableSessionNotActiveException;
use App\Exceptions\Public\CustomerOrderingDisabledException;
use App\Models\Order;
use App\Models\Table;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Creates an order from the public QR surface. Resolves the table exactly
 * like GET /public/tables/{token} does (public_token -> Table -> Restaurant
 * -> Organization; never TenantContext), but additionally requires an
 * active TableSession — unlike the menu, ordering is not allowed without
 * one. The session is re-resolved here rather than trusted from an earlier
 * GET, since it may have closed in between.
 *
 * Idempotency-Key replay is looked up BEFORE any feature-toggle or
 * session-state check (see execute()): a request that already succeeded
 * once must keep returning that exact result forever after, regardless of
 * what an owner changes in RestaurantSettings afterward, and regardless of
 * whether the table's session has since closed — recovering an existing
 * result is not a new operation, so it isn't gated by rules that only
 * apply to creating one.
 */
class CreatePublicOrderAction
{
    public function __construct(
        private readonly ResolvePublicTableAction $resolvePublicTable,
        private readonly OrderCreationService $orderCreationService,
    ) {}

    /**
     * @param  array{customer_name?: ?string, note?: ?string, items: array<int, array<string, mixed>>, idempotency_key?: ?string}  $data
     * @return array{order: Order, replayed: bool}
     */
    public function execute(string $publicToken, array $data, string $locale): array
    {
        $table = $this->resolvePublicTable->execute($publicToken);

        $idempotencyKey = $data['idempotency_key'] ?? null;
        $payloadHash = $idempotencyKey !== null ? $this->payloadHash($data) : null;

        if ($idempotencyKey !== null) {
            $existing = $this->findReplayCandidate($table, $idempotencyKey);

            if ($existing) {
                return $this->replayOrConflict($existing, $payloadHash);
            }
        }

        // No existing order for this key (or no key at all): this is a
        // genuine creation attempt, so the full validation chain applies.
        // Ordering per "Ordem de validação pública": Table -> Restaurant ->
        // settings -> feature enabled -> active session -> validation ->
        // creation. Checked here, never before the replay lookup above.
        if (! $table->restaurant->settings->customer_ordering_enabled) {
            throw new CustomerOrderingDisabledException;
        }

        $session = $table->activeSession;

        if (! $session) {
            throw new TableSessionNotActiveException;
        }

        try {
            $order = $this->orderCreationService->execute(
                table: $table,
                tableSessionId: $session->id,
                origin: Order::ORIGIN_CUSTOMER_QR,
                createdBy: null,
                locale: $locale,
                items: $data['items'],
                customerName: $data['customer_name'] ?? null,
                customerNote: $data['note'] ?? null,
                idempotencyKey: $idempotencyKey,
                idempotencyPayloadHash: $payloadHash,
                requiresApproval: $table->restaurant->settings->customer_order_requires_approval,
            );
        } catch (UniqueConstraintViolationException $e) {
            // Lost a race against another request using the same key: the
            // other request's commit is what we would have produced.
            if ($idempotencyKey === null) {
                throw $e;
            }

            $existing = $this->findReplayCandidate($table, $idempotencyKey);

            if (! $existing) {
                throw $e;
            }

            return $this->replayOrConflict($existing, $payloadHash);
        }

        return ['order' => $order, 'replayed' => false];
    }

    /**
     * Locates a prior order to replay for this exact Idempotency-Key,
     * reconciling two guarantees that would otherwise conflict:
     *
     *   - A key is scoped to this Table (derived from the public_token —
     *     never a different restaurant/organization/table), but NOT
     *     strictly to "the table's current active session": the session
     *     that produced the original order may have closed since a
     *     legitimate retry was sent, and recovering that order is still
     *     the correct behavior for a replay (see class docblock) —
     *     otherwise a client retrying after the table closed its bill
     *     could never recover its own already-created order.
     *   - A brand new session opened at the same table must NOT inherit
     *     an old session's idempotency keys: if a *different* session is
     *     currently active than the one the matched order belongs to,
     *     this is a fresh operation in a new session, not a replay of a
     *     stale one, even if the client happens to reuse the same key
     *     string.
     *
     * So: no active session at all (closed, matching the matched order or
     * not) -> the matched order is a valid replay candidate. An active
     * session exists and it's the SAME one the matched order belongs to
     * -> valid replay candidate. An active session exists but it's a
     * DIFFERENT one -> not a replay candidate; the caller proceeds to
     * create a new order in the new session instead.
     */
    private function findReplayCandidate(Table $table, string $idempotencyKey): ?Order
    {
        $existing = Order::query()
            ->where('table_id', $table->id)
            ->where('origin', Order::ORIGIN_CUSTOMER_QR)
            ->where('idempotency_key', $idempotencyKey)
            ->latest('id')
            ->first();

        if (! $existing) {
            return null;
        }

        $activeSessionId = $table->activeSession?->id;

        if ($activeSessionId !== null && $activeSessionId !== $existing->table_session_id) {
            return null;
        }

        return $existing;
    }

    /**
     * @return array{order: Order, replayed: bool}
     */
    private function replayOrConflict(Order $existing, ?string $payloadHash): array
    {
        if ($existing->idempotency_payload_hash !== $payloadHash) {
            throw new IdempotencyKeyReusedException;
        }

        return ['order' => $existing->load('items.modifiers'), 'replayed' => true];
    }

    /**
     * A simple hash of the normalized request payload — enough to detect
     * the same Idempotency-Key being reused for a materially different
     * order, without a full canonical-JSON implementation.
     *
     * @param  array<string, mixed>  $data
     */
    private function payloadHash(array $data): string
    {
        return hash('sha256', json_encode([
            'customer_name' => $data['customer_name'] ?? null,
            'note' => $data['note'] ?? null,
            'items' => $data['items'],
        ]));
    }
}
