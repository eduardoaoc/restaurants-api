<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single, immutable domain audit event — who did what, when, on which
 * Restaurant, about which resource. Created exclusively through
 * App\Support\Audit\AuditLogger from explicit call sites inside Actions
 * (and a couple of simple Controllers with no Action of their own); there
 * is no generic model-saved Observer wired up anywhere, on purpose — see
 * the Bloco 16 report for why.
 *
 * Append-only: no update()/delete() is ever called on this model by
 * application code, and there is no API route that could either.
 */
#[Fillable([
    'organization_id', 'restaurant_id', 'actor_user_id', 'actor_type',
    'event', 'resource_type', 'resource_id', 'changes', 'metadata', 'created_at',
])]
class AuditLog extends Model
{
    /**
     * Append-only, no updated_at — see the migration.
     */
    public $timestamps = false;

    public const ACTOR_USER = 'user';

    public const ACTOR_PUBLIC = 'public';

    public const ACTOR_SYSTEM = 'system';

    /**
     * A platform admin acting through the /platform namespace. Still a
     * real User row (actor_user_id is populated the same way as
     * ACTOR_USER) — this constant only distinguishes platform-level
     * activity from ordinary tenant activity in the trail, since a
     * platform admin's own User row is not itself scoped to any
     * organization the way a tenant actor's is.
     */
    public const ACTOR_PLATFORM_ADMIN = 'platform_admin';

    public const ACTOR_TYPES = [self::ACTOR_USER, self::ACTOR_PUBLIC, self::ACTOR_SYSTEM, self::ACTOR_PLATFORM_ADMIN];

    public const EVENT_STAFF_CREATED = 'staff.created';

    public const EVENT_STAFF_UPDATED = 'staff.updated';

    public const EVENT_TABLE_SESSION_OPENED = 'table_session.opened';

    public const EVENT_TABLE_SESSION_CLOSED = 'table_session.closed';

    public const EVENT_ORDER_CREATED = 'order.created';

    public const EVENT_ORDER_APPROVED = 'order.approved';

    public const EVENT_ORDER_REJECTED = 'order.rejected';

    public const EVENT_ORDER_ACCEPTED = 'order.accepted';

    public const EVENT_ORDER_PREPARING = 'order.preparing';

    public const EVENT_ORDER_READY = 'order.ready';

    public const EVENT_ORDER_SERVED = 'order.served';

    public const EVENT_TABLE_REQUEST_CREATED = 'table_request.created';

    public const EVENT_TABLE_REQUEST_ACKNOWLEDGED = 'table_request.acknowledged';

    public const EVENT_TABLE_REQUEST_COMPLETED = 'table_request.completed';

    public const EVENT_TABLE_REQUEST_CANCELLED = 'table_request.cancelled';

    public const EVENT_PAYMENT_RECORD_CREATED = 'payment_record.created';

    public const EVENT_STAFF_REVIEW_CREATED = 'staff_review.created';

    public const EVENT_PRINT_RECORD_CREATED = 'print_record.created';

    public const EVENT_RESTAURANT_SETTINGS_UPDATED = 'restaurant.settings_updated';

    // Platform-level events (Bloco 0) — recorded with actor_type
    // ACTOR_PLATFORM_ADMIN, always through PlatformAuditLogger.
    public const EVENT_PLATFORM_USER_SUSPENDED = 'platform.user.suspended';

    public const EVENT_PLATFORM_USER_REACTIVATED = 'platform.user.reactivated';

    public const EVENT_PLATFORM_ORGANIZATION_SUSPENDED = 'platform.organization.suspended';

    public const EVENT_PLATFORM_ORGANIZATION_REACTIVATED = 'platform.organization.reactivated';

    public const EVENT_PLATFORM_ORGANIZATION_PLAN_CHANGED = 'platform.organization.plan_changed';

    public const EVENT_PLATFORM_RESTAURANT_SUSPENDED = 'platform.restaurant.suspended';

    public const EVENT_PLATFORM_RESTAURANT_REACTIVATED = 'platform.restaurant.reactivated';

    // Floor Plan events (Bloco 1).
    public const EVENT_FLOOR_CREATED = 'floor.created';

    public const EVENT_FLOOR_UPDATED = 'floor.updated';

    public const EVENT_FLOOR_DELETED = 'floor.deleted';

    public const EVENT_ZONE_CREATED = 'zone.created';

    public const EVENT_ZONE_UPDATED = 'zone.updated';

    public const EVENT_ZONE_DELETED = 'zone.deleted';

    // One aggregated event per bulk save, never one per table — see
    // FloorPlanController::updateLayout / the Bloco 1 report.
    public const EVENT_FLOOR_PLAN_LAYOUT_UPDATED = 'floor_plan.layout.updated';

    /**
     * @var array<int, string>
     */
    public const EVENTS = [
        self::EVENT_STAFF_CREATED,
        self::EVENT_STAFF_UPDATED,
        self::EVENT_TABLE_SESSION_OPENED,
        self::EVENT_TABLE_SESSION_CLOSED,
        self::EVENT_ORDER_CREATED,
        self::EVENT_ORDER_APPROVED,
        self::EVENT_ORDER_REJECTED,
        self::EVENT_ORDER_ACCEPTED,
        self::EVENT_ORDER_PREPARING,
        self::EVENT_ORDER_READY,
        self::EVENT_ORDER_SERVED,
        self::EVENT_TABLE_REQUEST_CREATED,
        self::EVENT_TABLE_REQUEST_ACKNOWLEDGED,
        self::EVENT_TABLE_REQUEST_COMPLETED,
        self::EVENT_TABLE_REQUEST_CANCELLED,
        self::EVENT_PAYMENT_RECORD_CREATED,
        self::EVENT_STAFF_REVIEW_CREATED,
        self::EVENT_PRINT_RECORD_CREATED,
        self::EVENT_RESTAURANT_SETTINGS_UPDATED,
        self::EVENT_PLATFORM_USER_SUSPENDED,
        self::EVENT_PLATFORM_USER_REACTIVATED,
        self::EVENT_PLATFORM_ORGANIZATION_SUSPENDED,
        self::EVENT_PLATFORM_ORGANIZATION_REACTIVATED,
        self::EVENT_PLATFORM_ORGANIZATION_PLAN_CHANGED,
        self::EVENT_PLATFORM_RESTAURANT_SUSPENDED,
        self::EVENT_PLATFORM_RESTAURANT_REACTIVATED,
        self::EVENT_FLOOR_CREATED,
        self::EVENT_FLOOR_UPDATED,
        self::EVENT_FLOOR_DELETED,
        self::EVENT_ZONE_CREATED,
        self::EVENT_ZONE_UPDATED,
        self::EVENT_ZONE_DELETED,
        self::EVENT_FLOOR_PLAN_LAYOUT_UPDATED,
    ];

    public const RESOURCE_STAFF = 'staff';

    public const RESOURCE_RESTAURANT = 'restaurant';

    public const RESOURCE_TABLE_SESSION = 'table_session';

    public const RESOURCE_ORDER = 'order';

    public const RESOURCE_TABLE_REQUEST = 'table_request';

    public const RESOURCE_PAYMENT_RECORD = 'payment_record';

    public const RESOURCE_STAFF_REVIEW = 'staff_review';

    public const RESOURCE_PRINT_RECORD = 'print_record';

    public const RESOURCE_USER = 'user';

    public const RESOURCE_ORGANIZATION = 'organization';

    public const RESOURCE_FLOOR = 'floor';

    public const RESOURCE_ZONE = 'zone';

    /**
     * @var array<int, string>
     */
    public const RESOURCE_TYPES = [
        self::RESOURCE_STAFF,
        self::RESOURCE_TABLE_SESSION,
        self::RESOURCE_ORDER,
        self::RESOURCE_TABLE_REQUEST,
        self::RESOURCE_PAYMENT_RECORD,
        self::RESOURCE_STAFF_REVIEW,
        self::RESOURCE_PRINT_RECORD,
        self::RESOURCE_RESTAURANT,
        self::RESOURCE_USER,
        self::RESOURCE_ORGANIZATION,
        self::RESOURCE_FLOOR,
        self::RESOURCE_ZONE,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'changes' => 'array',
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
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
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
