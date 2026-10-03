<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One WhatsApp delivery attempt of a Cierre Diario (CARTA 9.1E) — see the
 * migration for the status semantics. The recipient phone is a snapshot,
 * encrypted at rest and hidden; only recipient_phone_masked is exposed.
 */
#[Fillable([
    'restaurant_day_close_id', 'restaurant_id', 'channel', 'kind', 'status',
    'recipient_id', 'recipient_name_snapshot', 'recipient_phone_e164', 'recipient_phone_masked', 'recipient_phone_hash',
    'template_name', 'template_language', 'provider_message_id',
    'requested_by_user_id', 'requested_by_name_snapshot', 'attempts', 'failure_code', 'failure_reason',
    'idempotency_key', 'payload_hash', 'sending_started_at',
    'queued_at', 'accepted_at', 'sent_at', 'delivered_at', 'read_at', 'failed_at',
])]
#[Hidden(['recipient_phone_e164', 'recipient_phone_hash', 'payload_hash'])]
class RestaurantDayCloseDelivery extends Model
{
    public const KIND_AUTOMATIC = 'automatic';

    public const KIND_MANUAL_RESEND = 'manual_resend';

    public const STATUS_PENDING = 'pending';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_SENT = 'sent';

    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_READ = 'read';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SKIPPED = 'skipped';

    /**
     * Forward-only progression; a status never moves to a lower rank.
     * failed/skipped are handled apart (terminal).
     *
     * @var array<string, int>
     */
    public const PROGRESSION = [
        self::STATUS_PENDING => 0,
        self::STATUS_ACCEPTED => 1,
        self::STATUS_SENT => 2,
        self::STATUS_DELIVERED => 3,
        self::STATUS_READ => 4,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'recipient_phone_e164' => 'encrypted',
            'attempts' => 'integer',
            'sending_started_at' => 'datetime',
            'queued_at' => 'datetime',
            'accepted_at' => 'datetime',
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'read_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<RestaurantDayClose, $this>
     */
    public function dayClose(): BelongsTo
    {
        return $this->belongsTo(RestaurantDayClose::class, 'restaurant_day_close_id');
    }
}
