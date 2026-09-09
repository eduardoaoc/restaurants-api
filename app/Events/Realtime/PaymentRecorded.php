<?php

namespace App\Events\Realtime;

use Illuminate\Support\Carbon;

/**
 * Dispatched by RecordPaymentAction after a PaymentRecord is committed —
 * never on an idempotency replay (see the call site). amount/method are
 * the same fields already exposed to the managerial dashboard; never a
 * card number, gateway token, or other payment credential.
 */
class PaymentRecorded extends RealtimeEvent
{
    public function __construct(
        int $restaurantId,
        public readonly int $tableSessionId,
        public readonly int $tableId,
        public readonly int $paymentId,
        public readonly string $amount,
        public readonly string $paymentMethod,
        public readonly Carbon $recordedAt,
    ) {
        parent::__construct($restaurantId);
    }

    public function broadcastAs(): string
    {
        return 'payment.recorded';
    }

    protected function payload(): array
    {
        return [
            'table_session_id' => $this->tableSessionId,
            'table_id' => $this->tableId,
            'payment_id' => $this->paymentId,
            'amount' => $this->amount,
            'payment_method' => $this->paymentMethod,
            'recorded_at' => $this->recordedAt->toISOString(),
        ];
    }
}
