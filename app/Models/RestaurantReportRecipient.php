<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Who receives the Cierre Diario by WhatsApp for one restaurant (CARTA
 * 9.1E). phone_e164 is encrypted at rest and hidden from serialization;
 * only phone_masked is ever exposed. A recipient is usable only while
 * active with a recorded, non-revoked consent (hasValidConsent()).
 */
#[Fillable([
    'organization_id', 'restaurant_id', 'user_id', 'channel', 'name',
    'phone_e164', 'phone_hash', 'phone_masked', 'active',
    'consent_given_at', 'consent_recorded_by_user_id', 'consent_method', 'consent_text_version', 'consent_revoked_at',
])]
#[Hidden(['phone_e164', 'phone_hash'])]
class RestaurantReportRecipient extends Model
{
    public const CHANNEL_WHATSAPP = 'whatsapp';

    public const CONSENT_METHOD_DECLARED_BY_ADMIN = 'declared_by_admin';

    public const CONSENT_TEXT_VERSION = 'v1';

    /**
     * The exact text the admin confirms (consent_text_version v1).
     */
    public const CONSENT_TEXT = 'Confirmo que esta persona ha aceptado recibir los cierres diarios por WhatsApp.';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'phone_e164' => 'encrypted',
            'active' => 'boolean',
            'consent_given_at' => 'datetime',
            'consent_revoked_at' => 'datetime',
        ];
    }

    public function hasValidConsent(): bool
    {
        return $this->consent_given_at !== null && $this->consent_revoked_at === null;
    }

    public function isUsable(): bool
    {
        return $this->active && $this->hasValidConsent();
    }

    /**
     * @return BelongsTo<Restaurant, $this>
     */
    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
