<?php

namespace App\Actions\Billing;

use App\Exceptions\Billing\PaymentExceedsBalanceException;
use App\Exceptions\Billing\PaymentIdempotencyKeyReusedException;
use App\Exceptions\Billing\TableSessionAlreadyPaidException;
use App\Exceptions\Billing\TableSessionClosedException;
use App\Exceptions\Billing\TableSessionHasNoBillableOrdersException;
use App\Models\AuditLog;
use App\Models\PaymentRecord;
use App\Models\TableSession;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Billing\SessionBillCalculator;
use App\Support\Money\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Records a manual payment against a table session's bill.
 *
 * Runs inside one transaction, locking the TableSession row
 * (lockForUpdate) so a concurrent payment on the same session can't both
 * succeed past the balance it observed, and so it's naturally serialized
 * against a concurrent order/table-request creation (which lock the same
 * row to check payment_status) and against a concurrent close.
 *
 * Idempotency-Key replay is checked BEFORE the already-paid check — on
 * purpose: the payment that completes the bill is exactly the one most
 * likely to be retried (e.g. a flaky network response after the server
 * already committed), and it must still replay successfully even though
 * the session is now paid. See report for the full ordering rationale.
 */
class RecordPaymentAction
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * @param  array{method: string, amount: string, reference?: ?string, note?: ?string, idempotency_key?: ?string}  $data
     * @return array{payment: PaymentRecord, replayed: bool}
     */
    public function execute(TableSession $session, User $recordedBy, array $data): array
    {
        return DB::transaction(function () use ($session, $recordedBy, $data) {
            $locked = TableSession::query()->whereKey($session->id)->lockForUpdate()->first();

            if (! $locked || ! $locked->isActive()) {
                throw new TableSessionClosedException;
            }

            $idempotencyKey = $data['idempotency_key'] ?? null;
            $payloadHash = $idempotencyKey !== null ? $this->payloadHash($data) : null;

            if ($idempotencyKey !== null) {
                $existing = PaymentRecord::query()
                    ->where('table_session_id', $locked->id)
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();

                if ($existing) {
                    return $this->replayOrConflict($existing, $payloadHash);
                }
            }

            if ($locked->isPaid()) {
                throw new TableSessionAlreadyPaidException;
            }

            $summary = SessionBillCalculator::summarize($locked);

            if (! $summary['hasBillableOrders']) {
                throw new TableSessionHasNoBillableOrdersException;
            }

            $amountCents = Money::decimalToCents($data['amount']);

            if ($amountCents > $summary['balanceCents']) {
                throw new PaymentExceedsBalanceException;
            }

            try {
                $payment = PaymentRecord::query()->create([
                    'restaurant_id' => $locked->restaurant_id,
                    'table_id' => $locked->table_id,
                    'table_session_id' => $locked->id,
                    'method' => $data['method'],
                    'amount' => Money::centsToDecimal($amountCents),
                    'currency' => PaymentRecord::CURRENCY_EUR,
                    'reference' => $data['reference'] ?? null,
                    'note' => $data['note'] ?? null,
                    'idempotency_key' => $idempotencyKey,
                    'payload_hash' => $payloadHash,
                    'recorded_by_user_id' => $recordedBy->id,
                    'recorded_at' => now(),
                ]);
            } catch (UniqueConstraintViolationException $e) {
                // Lost a race against another request using the same key.
                if ($idempotencyKey === null) {
                    throw $e;
                }

                $existing = PaymentRecord::query()
                    ->where('table_session_id', $locked->id)
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();

                if (! $existing) {
                    throw $e;
                }

                return $this->replayOrConflict($existing, $payloadHash);
            }

            $this->auditLogger->log(
                organizationId: $locked->restaurant->organization_id,
                restaurantId: $locked->restaurant_id,
                actorType: AuditLog::ACTOR_USER,
                actor: $recordedBy,
                event: AuditLog::EVENT_PAYMENT_RECORD_CREATED,
                resourceType: AuditLog::RESOURCE_PAYMENT_RECORD,
                resourceId: $payment->id,
                metadata: [
                    'table_session_id' => $locked->id,
                    'method' => $payment->method,
                    'amount' => $payment->amount,
                    'currency' => $payment->currency,
                ],
            );

            $newPaidTotalCents = $summary['paidTotalCents'] + $amountCents;

            if ($newPaidTotalCents === $summary['ordersTotalCents']) {
                $locked->update([
                    'payment_status' => TableSession::PAYMENT_STATUS_PAID,
                    'paid_at' => now(),
                ]);
            }

            return ['payment' => $payment, 'replayed' => false];
        });
    }

    /**
     * @return array{payment: PaymentRecord, replayed: bool}
     */
    private function replayOrConflict(PaymentRecord $existing, ?string $payloadHash): array
    {
        if ($existing->payload_hash !== $payloadHash) {
            throw new PaymentIdempotencyKeyReusedException;
        }

        return ['payment' => $existing, 'replayed' => true];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function payloadHash(array $data): string
    {
        return hash('sha256', json_encode([
            'method' => $data['method'],
            'amount' => $data['amount'],
            'reference' => $data['reference'] ?? null,
            'note' => $data['note'] ?? null,
        ]));
    }
}
