<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Restaurant's operational configuration — one-to-one, always present
 * (see RestaurantFactory::configure() and the Bloco 18 backfill migration
 * for existing Restaurants). Never persists derived/aggregate numbers
 * (that's Restaurant Dashboard's job) — only configuration a human chose.
 */
#[Fillable([
    'organization_id', 'restaurant_id',
    'default_locale', 'enabled_locales',
    'currency', 'timezone',
    'customer_ordering_enabled', 'customer_order_requires_approval',
    'waiter_call_enabled', 'bill_request_enabled',
    'kitchen_ticket_printing_enabled', 'bill_receipt_printing_enabled',
    'google_review_url',
    'waiter_table_management_enabled',
    'business_day_cutoff_time', 'default_opening_float', 'cash_difference_note_threshold',
    'accept_delay_threshold_minutes', 'preparation_delay_threshold_minutes', 'ready_pickup_delay_threshold_minutes',
    'daily_close_whatsapp_enabled',
])]
class RestaurantSettings extends Model
{
    public const LOCALE_ES_ES = 'es-ES';

    public const LOCALE_CA_ES_VALENCIA = 'ca-ES-valencia';

    public const LOCALE_EN_GB = 'en-GB';

    /**
     * The platform's full locale allowlist. enabled_locales is always a
     * subset of this — it is not itself an arbitrary free-form list.
     *
     * @var array<int, string>
     */
    public const SUPPORTED_LOCALES = [self::LOCALE_ES_ES, self::LOCALE_CA_ES_VALENCIA, self::LOCALE_EN_GB];

    public const DEFAULT_LOCALE = self::LOCALE_ES_ES;

    /**
     * @var array<int, string>
     */
    public const DEFAULT_ENABLED_LOCALES = [self::LOCALE_ES_ES, self::LOCALE_CA_ES_VALENCIA, self::LOCALE_EN_GB];

    public const CURRENCY_EUR = 'EUR';

    /**
     * ISO 4217 codes accepted by this MVP. Spain-only market for now — the
     * column stays string(3) so a second currency is a data change, not a
     * schema change.
     *
     * @var array<int, string>
     */
    public const SUPPORTED_CURRENCIES = [self::CURRENCY_EUR];

    public const DEFAULT_CURRENCY = self::CURRENCY_EUR;

    public const DEFAULT_TIMEZONE = 'Europe/Madrid';

    /**
     * CARTA 8.2A: true preserves the pre-existing behavior (a waiter with
     * manage_tables may create/rename tables). Same value as the column's
     * DB default — see TablePolicy::canManageStructure().
     */
    public const DEFAULT_WAITER_TABLE_MANAGEMENT_ENABLED = true;

    /**
     * Cierre Diario defaults (CARTA 9.1A) — same values as the columns'
     * DB defaults. business_day_cutoff_time is local "HH:MM": a close whose
     * local time is before it belongs to the previous business date.
     */
    public const DEFAULT_BUSINESS_DAY_CUTOFF_TIME = '06:00';

    public const DEFAULT_CASH_DIFFERENCE_NOTE_THRESHOLD = '5.00';

    public const DEFAULT_ACCEPT_DELAY_THRESHOLD_MINUTES = 10;

    public const DEFAULT_PREPARATION_DELAY_THRESHOLD_MINUTES = 30;

    public const DEFAULT_READY_PICKUP_DELAY_THRESHOLD_MINUTES = 10;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'enabled_locales' => 'array',
            'customer_ordering_enabled' => 'boolean',
            'customer_order_requires_approval' => 'boolean',
            'waiter_call_enabled' => 'boolean',
            'bill_request_enabled' => 'boolean',
            'kitchen_ticket_printing_enabled' => 'boolean',
            'bill_receipt_printing_enabled' => 'boolean',
            'waiter_table_management_enabled' => 'boolean',
            'default_opening_float' => 'decimal:2',
            'cash_difference_note_threshold' => 'decimal:2',
            'accept_delay_threshold_minutes' => 'integer',
            'preparation_delay_threshold_minutes' => 'integer',
            'ready_pickup_delay_threshold_minutes' => 'integer',
            'daily_close_whatsapp_enabled' => 'boolean',
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
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Create the default settings row for a newly created Restaurant —
     * the initial market is Valencia/Spain (see report). Used both by
     * RestaurantController::store() (inside its transaction) and by
     * RestaurantFactory for tests.
     */
    public static function createDefaultsFor(Restaurant $restaurant): self
    {
        return self::query()->create([
            'organization_id' => $restaurant->organization_id,
            'restaurant_id' => $restaurant->id,
            'default_locale' => self::DEFAULT_LOCALE,
            'enabled_locales' => self::DEFAULT_ENABLED_LOCALES,
            'currency' => self::DEFAULT_CURRENCY,
            'timezone' => self::DEFAULT_TIMEZONE,
            'customer_ordering_enabled' => true,
            'customer_order_requires_approval' => false,
            'waiter_call_enabled' => true,
            'bill_request_enabled' => true,
            'kitchen_ticket_printing_enabled' => true,
            'bill_receipt_printing_enabled' => true,
            'waiter_table_management_enabled' => self::DEFAULT_WAITER_TABLE_MANAGEMENT_ENABLED,
            'business_day_cutoff_time' => self::DEFAULT_BUSINESS_DAY_CUTOFF_TIME,
            'cash_difference_note_threshold' => self::DEFAULT_CASH_DIFFERENCE_NOTE_THRESHOLD,
            'accept_delay_threshold_minutes' => self::DEFAULT_ACCEPT_DELAY_THRESHOLD_MINUTES,
            'preparation_delay_threshold_minutes' => self::DEFAULT_PREPARATION_DELAY_THRESHOLD_MINUTES,
            'ready_pickup_delay_threshold_minutes' => self::DEFAULT_READY_PICKUP_DELAY_THRESHOLD_MINUTES,
        ]);
    }
}
