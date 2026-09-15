<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;
use InvalidArgumentException;

#[Fillable([
    'restaurant_id', 'table_id', 'opened_by_user_id', 'closed_by_user_id', 'guest_count',
    'status', 'opened_at', 'closed_at', 'payment_status', 'paid_at', 'assigned_waiter_user_id',
    'feedback_token',
])]
class TableSession extends Model
{
    public const PAYMENT_STATUS_UNPAID = 'unpaid';

    public const PAYMENT_STATUS_PAID = 'paid';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Restaurant, $this>
     */
    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    /**
     * @return BelongsTo<Table, $this>
     */
    public function table(): BelongsTo
    {
        return $this->belongsTo(Table::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by_user_id');
    }

    /**
     * The waiter currently responsible for this session, if any (Bloco 2).
     * Belongs to the session, not the Table — see the migration.
     *
     * @return BelongsTo<User, $this>
     */
    public function assignedWaiter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_waiter_user_id');
    }

    public function isActive(): bool
    {
        return $this->status !== 'closed';
    }

    public function isPaid(): bool
    {
        return $this->payment_status === self::PAYMENT_STATUS_PAID;
    }

    /**
     * Whether this session has a still-open (pending or acknowledged)
     * request_bill TableRequest — the real domain signal that the customer
     * has asked for the bill, used to gate further public ordering. See
     * TableRequest::openStatuses().
     */
    public function hasOpenBillRequest(): bool
    {
        return $this->tableRequests()
            ->where('type', TableRequest::TYPE_REQUEST_BILL)
            ->whereIn('status', TableRequest::openStatuses())
            ->exists();
    }

    /**
     * Whether a CustomerFeedback row already exists for this visit (Passo
     * 3.5 §4 — at most one per session).
     */
    public function hasSubmittedFeedback(): bool
    {
        return $this->customerFeedback()->exists();
    }

    /**
     * Generate an unpredictable, high-entropy feedback token — same
     * pattern as Table::generateUniquePublicToken(), not derived from the
     * id. Called once, at session-open time (see OpenTableAction).
     */
    public static function generateUniqueFeedbackToken(): string
    {
        do {
            $token = Str::random(48);
        } while (self::query()->where('feedback_token', $token)->exists());

        return $token;
    }

    /**
     * Backfills feedback_token for a session that predates the
     * token-lifecycle fix (Passo 3.5): every session opened via
     * OpenTableAction already has one, but a session that was still active
     * across the deploy that introduced open-time generation would not.
     * Safe to call on every resolution — a no-op once the column is set.
     * Public-read-triggered (PublicSessionStateResource), so this is a
     * lazy backfill by design, not a migration-time one: it only ever
     * touches a session the moment it's actually being resolved for a
     * customer, and never resurrects a CLOSED session (see
     * PublicSessionStateResource, which only ever receives activeSession).
     */
    public function ensureFeedbackToken(): string
    {
        if ($this->feedback_token === null) {
            $this->feedback_token = self::generateUniqueFeedbackToken();
            $this->save();
        }

        return $this->feedback_token;
    }

    /**
     * The orders placed during this session. A session can accumulate many
     * orders; a new session (after this one closes) starts with none.
     *
     * @return HasMany<Order, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * The call_waiter/request_bill requests placed during this session.
     *
     * @return HasMany<TableRequest, $this>
     */
    public function tableRequests(): HasMany
    {
        return $this->hasMany(TableRequest::class);
    }

    /**
     * The manual payment records collected against this session's bill.
     *
     * @return HasMany<PaymentRecord, $this>
     */
    public function paymentRecords(): HasMany
    {
        return $this->hasMany(PaymentRecord::class);
    }

    /**
     * This visit's customer feedback, if the customer has submitted one
     * (Passo 3.5) — at most one per session, enforced by a unique
     * constraint on customer_feedbacks.table_session_id.
     *
     * @return HasOne<CustomerFeedback, $this>
     */
    public function customerFeedback(): HasOne
    {
        return $this->hasOne(CustomerFeedback::class);
    }

    protected static function booted(): void
    {
        static::saving(function (self $session) {
            $tableRestaurantId = Table::query()->whereKey($session->table_id)->value('restaurant_id');

            if ($tableRestaurantId !== $session->restaurant_id) {
                throw new InvalidArgumentException('The table does not belong to the given restaurant.');
            }
        });
    }
}
