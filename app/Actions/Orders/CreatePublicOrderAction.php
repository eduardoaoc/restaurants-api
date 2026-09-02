<?php

namespace App\Actions\Orders;

use App\Actions\Public\ResolvePublicTableAction;
use App\Exceptions\Orders\IdempotencyKeyReusedException;
use App\Exceptions\Orders\TableSessionNotActiveException;
use App\Models\Order;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Creates an order from the public QR surface. Resolves the table exactly
 * like GET /public/tables/{token} does (public_token -> Table -> Restaurant
 * -> Organization; never TenantContext), but additionally requires an
 * active TableSession — unlike the menu, ordering is not allowed without
 * one. The session is re-resolved here rather than trusted from an earlier
 * GET, since it may have closed in between.
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
        $session = $table->activeSession;

        if (! $session) {
            throw new TableSessionNotActiveException;
        }

        $idempotencyKey = $data['idempotency_key'] ?? null;
        $payloadHash = $idempotencyKey !== null ? $this->payloadHash($data) : null;

        if ($idempotencyKey !== null) {
            $existing = Order::query()
                ->where('table_session_id', $session->id)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing) {
                return $this->replayOrConflict($existing, $payloadHash);
            }
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
            );
        } catch (UniqueConstraintViolationException $e) {
            // Lost a race against another request using the same key: the
            // other request's commit is what we would have produced.
            if ($idempotencyKey === null) {
                throw $e;
            }

            $existing = Order::query()
                ->where('table_session_id', $session->id)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if (! $existing) {
                throw $e;
            }

            return $this->replayOrConflict($existing, $payloadHash);
        }

        return ['order' => $order, 'replayed' => false];
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
