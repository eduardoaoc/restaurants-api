<?php

namespace App\Support\Activity;

use InvalidArgumentException;

/**
 * The single catalog of operational activity types (CARTA 6.1A): each
 * type's wire name, its category, and the exact metadata keys it may
 * carry. No call site spells a type string or a category by hand.
 *
 * Only transitions that really exist in the domain are listed:
 *   - order.rejected is the only way an order becomes `cancelled` today
 *     (RejectOrderAction), and its cause is known, so there is no generic
 *     order.cancelled type;
 *   - product.* is about RestaurantProduct.available — an on/off
 *     availability flag, not stock (AFORO has no stock module).
 *
 * Categories are for grouping in a UI only. Each type also has a tone —
 * a semantic presentation hint (neutral/positive/warning/critical) the
 * client maps to colors/icons; the backend never returns colors. Tone is
 * derived from the type (never persisted), so reclassifying a type needs
 * no UPDATE of history and old rows get it too. Tone is NOT "requires
 * action": order.ready is positive here even while Operations Live may
 * alert that it is waiting to be picked up — current state and required
 * action stay the Operations Live alerts' job, never this history's.
 */
final class RestaurantActivityType
{
    public const CATEGORY_ORDERS = 'orders';

    public const CATEGORY_SERVICE = 'service';

    public const CATEGORY_BILLING = 'billing';

    public const CATEGORY_TABLES = 'tables';

    public const CATEGORY_MENU = 'menu';

    /**
     * @var array<int, string>
     */
    public const CATEGORIES = [
        self::CATEGORY_ORDERS,
        self::CATEGORY_SERVICE,
        self::CATEGORY_BILLING,
        self::CATEGORY_TABLES,
        self::CATEGORY_MENU,
    ];

    /** Routine progress, no particular emphasis. */
    public const TONE_NEUTRAL = 'neutral';

    /** Something completed/resolved well. */
    public const TONE_POSITIVE = 'positive';

    /** Worth noticing (a guest asks for something, the menu shrinks). */
    public const TONE_WARNING = 'warning';

    /** Genuinely problematic — kept rare on purpose. */
    public const TONE_CRITICAL = 'critical';

    /**
     * @var array<int, string>
     */
    public const TONES = [self::TONE_NEUTRAL, self::TONE_POSITIVE, self::TONE_WARNING, self::TONE_CRITICAL];

    public const ORDER_CREATED = 'order.created';

    /** A waiting_approval customer order approved by staff (-> confirmed). */
    public const ORDER_APPROVED = 'order.approved';

    /** A waiting_approval customer order rejected by staff (-> cancelled). */
    public const ORDER_REJECTED = 'order.rejected';

    /** The kitchen took a confirmed order (-> accepted). */
    public const ORDER_ACCEPTED = 'order.accepted';

    public const ORDER_PREPARING = 'order.preparing';

    public const ORDER_READY = 'order.ready';

    public const ORDER_SERVED = 'order.served';

    public const WAITER_REQUEST_CREATED = 'waiter_request.created';

    public const WAITER_REQUEST_ACKNOWLEDGED = 'waiter_request.acknowledged';

    public const WAITER_REQUEST_COMPLETED = 'waiter_request.completed';

    public const BILL_REQUEST_CREATED = 'bill_request.created';

    public const BILL_REQUEST_ACKNOWLEDGED = 'bill_request.acknowledged';

    public const BILL_REQUEST_COMPLETED = 'bill_request.completed';

    public const PAYMENT_RECORDED = 'payment.recorded';

    public const TABLE_SESSION_OPENED = 'table_session.opened';

    public const TABLE_SESSION_CLOSED = 'table_session.closed';

    public const PRODUCT_MARKED_UNAVAILABLE = 'product.marked_unavailable';

    public const PRODUCT_MARKED_AVAILABLE = 'product.marked_available';

    /**
     * type => category.
     *
     * @var array<string, string>
     */
    public const CATEGORY_BY_TYPE = [
        self::ORDER_CREATED => self::CATEGORY_ORDERS,
        self::ORDER_APPROVED => self::CATEGORY_ORDERS,
        self::ORDER_REJECTED => self::CATEGORY_ORDERS,
        self::ORDER_ACCEPTED => self::CATEGORY_ORDERS,
        self::ORDER_PREPARING => self::CATEGORY_ORDERS,
        self::ORDER_READY => self::CATEGORY_ORDERS,
        self::ORDER_SERVED => self::CATEGORY_ORDERS,
        self::WAITER_REQUEST_CREATED => self::CATEGORY_SERVICE,
        self::WAITER_REQUEST_ACKNOWLEDGED => self::CATEGORY_SERVICE,
        self::WAITER_REQUEST_COMPLETED => self::CATEGORY_SERVICE,
        self::BILL_REQUEST_CREATED => self::CATEGORY_BILLING,
        self::BILL_REQUEST_ACKNOWLEDGED => self::CATEGORY_BILLING,
        self::BILL_REQUEST_COMPLETED => self::CATEGORY_BILLING,
        self::PAYMENT_RECORDED => self::CATEGORY_BILLING,
        self::TABLE_SESSION_OPENED => self::CATEGORY_TABLES,
        self::TABLE_SESSION_CLOSED => self::CATEGORY_TABLES,
        self::PRODUCT_MARKED_UNAVAILABLE => self::CATEGORY_MENU,
        self::PRODUCT_MARKED_AVAILABLE => self::CATEGORY_MENU,
    ];

    /**
     * type => tone. Every type is listed explicitly (see
     * RestaurantActivityToneTest) — there is no default.
     *
     * @var array<string, string>
     */
    public const TONE_BY_TYPE = [
        self::TABLE_SESSION_OPENED => self::TONE_NEUTRAL,
        self::ORDER_CREATED => self::TONE_NEUTRAL,
        self::ORDER_APPROVED => self::TONE_NEUTRAL,
        self::ORDER_ACCEPTED => self::TONE_NEUTRAL,
        self::ORDER_PREPARING => self::TONE_NEUTRAL,
        self::WAITER_REQUEST_ACKNOWLEDGED => self::TONE_NEUTRAL,
        self::BILL_REQUEST_ACKNOWLEDGED => self::TONE_NEUTRAL,
        self::ORDER_READY => self::TONE_POSITIVE,
        self::ORDER_SERVED => self::TONE_POSITIVE,
        self::PAYMENT_RECORDED => self::TONE_POSITIVE,
        self::TABLE_SESSION_CLOSED => self::TONE_POSITIVE,
        self::WAITER_REQUEST_COMPLETED => self::TONE_POSITIVE,
        self::BILL_REQUEST_COMPLETED => self::TONE_POSITIVE,
        self::PRODUCT_MARKED_AVAILABLE => self::TONE_POSITIVE,
        self::WAITER_REQUEST_CREATED => self::TONE_WARNING,
        self::BILL_REQUEST_CREATED => self::TONE_WARNING,
        self::PRODUCT_MARKED_UNAVAILABLE => self::TONE_WARNING,
        self::ORDER_REJECTED => self::TONE_CRITICAL,
    ];

    /**
     * type => the only metadata keys it may carry (the per-type contract
     * of the `metadata` object — documented in OpenAPI). Every key listed
     * is always present for that type; types not listed carry no metadata.
     *
     *   order.created       origin (customer_qr|waiter), initial_status
     *                       (waiting_approval|confirmed), item_count (sum
     *                       of line quantities), total (decimal string)
     *   payment.recorded    payment_id, amount (decimal string), method
     *   table_session.opened guest_count
     *   table_session.closed total (billable orders total, decimal string)
     *   product.*           restaurant_product_id, product_name (the
     *                       product's staff-facing internal_name)
     *
     * @var array<string, array<int, string>>
     */
    public const METADATA_KEYS = [
        self::ORDER_CREATED => ['origin', 'initial_status', 'item_count', 'total'],
        self::PAYMENT_RECORDED => ['payment_id', 'amount', 'method'],
        self::TABLE_SESSION_OPENED => ['guest_count'],
        self::TABLE_SESSION_CLOSED => ['total'],
        self::PRODUCT_MARKED_UNAVAILABLE => ['restaurant_product_id', 'product_name'],
        self::PRODUCT_MARKED_AVAILABLE => ['restaurant_product_id', 'product_name'],
    ];

    /**
     * @return array<int, string>
     */
    public static function all(): array
    {
        return array_keys(self::CATEGORY_BY_TYPE);
    }

    public static function categoryOf(string $type): ?string
    {
        return self::CATEGORY_BY_TYPE[$type] ?? null;
    }

    /**
     * @throws InvalidArgumentException for a type outside the catalog —
     *                                  never a silent default
     */
    public static function tone(string $type): string
    {
        return self::TONE_BY_TYPE[$type] ?? throw new InvalidArgumentException("Unknown activity type '{$type}'.");
    }

    /**
     * @return array<int, string>
     */
    public static function metadataKeysOf(string $type): array
    {
        return self::METADATA_KEYS[$type] ?? [];
    }
}
