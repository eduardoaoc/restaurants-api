<?php

namespace App\Actions\Restaurants;

use App\Models\AuditLog;
use App\Models\RestaurantSettings;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Updates a restaurant's operational settings. $actor is mandatory — no
 * fallback, no silent audit skip (same discipline as
 * CreateStaffAction/UpdateStaffAction after the Bloco 17 hardening).
 *
 * Audit `changes` is built from an explicit whitelist of every settings
 * column — never a raw dirty-attributes dump — and no event at all is
 * recorded when nothing actually changed (a no-op PATCH is still a valid
 * 200, just an unaudited one).
 */
class UpdateRestaurantSettingsAction
{
    /**
     * @var array<int, string>
     */
    private const WHITELISTED_FIELDS = [
        'default_locale', 'enabled_locales', 'currency', 'timezone',
        'customer_ordering_enabled', 'customer_order_requires_approval',
        'waiter_call_enabled', 'bill_request_enabled',
        'kitchen_ticket_printing_enabled', 'bill_receipt_printing_enabled',
    ];

    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(RestaurantSettings $settings, array $data, User $actor): RestaurantSettings
    {
        return DB::transaction(function () use ($settings, $data, $actor) {
            $original = $settings->only(self::WHITELISTED_FIELDS);

            $settings->fill(array_intersect_key($data, array_flip(self::WHITELISTED_FIELDS)));
            $settings->save();

            $changes = [];

            foreach (self::WHITELISTED_FIELDS as $field) {
                if ($settings->wasChanged($field)) {
                    $changes[$field] = ['old' => $original[$field], 'new' => $settings->getAttribute($field)];
                }
            }

            if ($changes !== []) {
                $this->auditLogger->log(
                    organizationId: $settings->organization_id,
                    restaurantId: $settings->restaurant_id,
                    actorType: AuditLog::ACTOR_USER,
                    actor: $actor,
                    event: AuditLog::EVENT_RESTAURANT_SETTINGS_UPDATED,
                    resourceType: AuditLog::RESOURCE_RESTAURANT,
                    resourceId: $settings->restaurant_id,
                    changes: $changes,
                );
            }

            return $settings;
        });
    }
}
