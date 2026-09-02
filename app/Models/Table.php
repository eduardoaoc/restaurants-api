<?php

namespace App\Models;

use Database\Factories\TableFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

#[Fillable(['restaurant_id', 'name', 'number', 'public_token', 'status'])]
class Table extends Model
{
    /** @use HasFactory<TableFactory> */
    use HasFactory;

    /**
     * The restaurant this table belongs to.
     *
     * @return BelongsTo<Restaurant, $this>
     */
    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    /**
     * All historical sessions for this table (open and closed).
     *
     * @return HasMany<TableSession, $this>
     */
    public function sessions(): HasMany
    {
        return $this->hasMany(TableSession::class);
    }

    /**
     * The single non-closed session for this table, if any. The partial
     * unique index on table_sessions guarantees there is at most one.
     *
     * @return HasOne<TableSession, $this>
     */
    public function activeSession(): HasOne
    {
        return $this->hasOne(TableSession::class)->where('status', '!=', 'closed');
    }

    /**
     * All historical table requests (call_waiter/request_bill) for this
     * table, across every session.
     *
     * @return HasMany<TableRequest, $this>
     */
    public function tableRequests(): HasMany
    {
        return $this->hasMany(TableRequest::class);
    }

    /**
     * All historical manual payment records for this table.
     *
     * @return HasMany<PaymentRecord, $this>
     */
    public function paymentRecords(): HasMany
    {
        return $this->hasMany(PaymentRecord::class);
    }

    /**
     * Generate an unpredictable public token that is not derived from the id.
     */
    public static function generateUniquePublicToken(): string
    {
        do {
            $token = Str::random(48);
        } while (self::query()->where('public_token', $token)->exists());

        return $token;
    }
}
