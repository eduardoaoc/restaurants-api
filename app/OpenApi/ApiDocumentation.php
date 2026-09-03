<?php

namespace App\OpenApi;

use OpenApi\Attributes as OA;

#[OA\Info(
    version: '1.0.0',
    title: 'Restaurants API',
    description: 'REST API for the Restaurants SaaS platform.'
)]
#[OA\Server(
    url: '/',
    description: 'Current environment'
)]
#[OA\SecurityScheme(
    securityScheme: 'sessionCookie',
    type: 'apiKey',
    description: 'Laravel session cookie. It is created automatically after a successful login and sent by the browser on subsequent requests.',
    name: 'laravel-session',
    in: 'cookie'
)]
#[OA\Schema(
    schema: 'User',
    required: ['id', 'name', 'email'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 1),
        new OA\Property(property: 'name', type: 'string', example: 'Example User'),
        new OA\Property(property: 'email', type: 'string', format: 'email', example: 'user@example.com'),
        new OA\Property(property: 'email_verified_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'Organization',
    required: ['id', 'name', 'slug', 'status'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 1),
        new OA\Property(property: 'name', type: 'string', example: 'Grupo Exemplo'),
        new OA\Property(property: 'slug', type: 'string', example: 'grupo-exemplo'),
        new OA\Property(property: 'status', type: 'string', example: 'active'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'Restaurant',
    required: ['id', 'organization_id', 'name', 'slug', 'status'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 1),
        new OA\Property(property: 'organization_id', type: 'integer', format: 'int64', example: 1),
        new OA\Property(property: 'name', type: 'string', example: 'Downtown Branch'),
        new OA\Property(property: 'slug', type: 'string', example: 'downtown-branch'),
        new OA\Property(property: 'status', type: 'string', example: 'active'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'Staff',
    description: 'An operational staff member with 1..N restaurant assignments — never a wildcard/all-restaurants marker (see report).',
    required: ['id', 'name', 'email', 'role', 'restaurants'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 10),
        new OA\Property(property: 'name', type: 'string', example: 'Carlos'),
        new OA\Property(property: 'email', type: 'string', format: 'email', example: 'carlos@example.com'),
        new OA\Property(
            property: 'role',
            description: 'The same operational role applies across every one of this staff member\'s restaurants in this MVP.',
            properties: [
                new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 3),
                new OA\Property(property: 'slug', type: 'string', example: 'waiter'),
            ],
            type: 'object'
        ),
        new OA\Property(
            property: 'restaurants',
            type: 'array',
            items: new OA\Items(ref: '#/components/schemas/StaffRestaurantAssignment')
        ),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'StaffRestaurantAssignment',
    required: ['id', 'name', 'sub_id'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 2),
        new OA\Property(property: 'name', type: 'string', example: 'Restaurante Centro'),
        new OA\Property(property: 'sub_id', type: 'string', example: 'W-023'),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'StaffRestaurantAssignmentInput',
    required: ['restaurant_id', 'sub_id'],
    properties: [
        new OA\Property(property: 'restaurant_id', type: 'integer', format: 'int64', example: 10),
        new OA\Property(property: 'sub_id', type: 'string', example: 'W-014'),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'CreateStaffRequest',
    required: ['name', 'email', 'password', 'role', 'restaurant_assignments'],
    properties: [
        new OA\Property(property: 'name', type: 'string', example: 'Carlos García'),
        new OA\Property(property: 'email', type: 'string', format: 'email', example: 'carlos@example.com'),
        new OA\Property(property: 'password', type: 'string', format: 'password', example: 'TemporaryPassword123!'),
        new OA\Property(property: 'role', type: 'string', example: 'waiter'),
        new OA\Property(
            property: 'restaurant_assignments',
            description: 'At least 1 required. Every restaurant_id must belong to the active organization and be reachable via the requester\'s own restaurant scope.',
            type: 'array',
            items: new OA\Items(ref: '#/components/schemas/StaffRestaurantAssignmentInput')
        ),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'UpdateStaffRequest',
    properties: [
        new OA\Property(property: 'name', type: 'string', example: 'Carlos García'),
        new OA\Property(property: 'email', type: 'string', format: 'email', example: 'carlos@example.com'),
        new OA\Property(property: 'role', type: 'string', example: 'waiter'),
        new OA\Property(
            property: 'restaurant_assignments',
            description: 'When sent, REPLACES the staff member\'s full restaurant set (at least 1 entry required — never empty).',
            type: 'array',
            items: new OA\Items(ref: '#/components/schemas/StaffRestaurantAssignmentInput')
        ),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'Table',
    required: ['id', 'restaurant_id', 'name', 'status', 'public_token', 'has_active_session'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 1),
        new OA\Property(property: 'restaurant_id', type: 'integer', format: 'int64', example: 2),
        new OA\Property(property: 'name', type: 'string', example: 'Mesa 12'),
        new OA\Property(property: 'number', type: 'integer', example: 12, nullable: true),
        new OA\Property(property: 'public_token', type: 'string', example: 'q8dJf83Kp...'),
        new OA\Property(property: 'status', type: 'string', example: 'active'),
        new OA\Property(property: 'has_active_session', type: 'boolean', example: true),
        new OA\Property(
            property: 'active_session',
            nullable: true,
            properties: [
                new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 827),
                new OA\Property(property: 'status', type: 'string', example: 'occupied'),
                new OA\Property(property: 'guest_count', type: 'integer', example: 4),
                new OA\Property(property: 'opened_at', type: 'string', format: 'date-time'),
            ],
            type: 'object'
        ),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'TableSession',
    required: ['id', 'table_id', 'restaurant_id', 'guest_count', 'status', 'opened_at'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 827),
        new OA\Property(property: 'table_id', type: 'integer', format: 'int64', example: 1),
        new OA\Property(property: 'restaurant_id', type: 'integer', format: 'int64', example: 2),
        new OA\Property(property: 'guest_count', type: 'integer', example: 4),
        new OA\Property(property: 'status', type: 'string', example: 'occupied'),
        new OA\Property(property: 'payment_status', type: 'string', example: 'unpaid'),
        new OA\Property(property: 'opened_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'closed_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'paid_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'opened_by_user_id', type: 'integer', format: 'int64', example: 5),
        new OA\Property(property: 'closed_by_user_id', type: 'integer', format: 'int64', example: 5, nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'Translation',
    required: ['locale', 'name'],
    properties: [
        new OA\Property(property: 'locale', type: 'string', example: 'en'),
        new OA\Property(property: 'name', type: 'string', example: 'Starters'),
        new OA\Property(property: 'description', type: 'string', example: 'Small dishes to start the meal.', nullable: true),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'Menu',
    required: ['id', 'restaurant_id', 'name', 'status'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 1),
        new OA\Property(property: 'restaurant_id', type: 'integer', format: 'int64', example: 2),
        new OA\Property(property: 'name', type: 'string', example: 'Main Menu'),
        new OA\Property(property: 'status', type: 'string', example: 'active'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'Category',
    required: ['id', 'menu_id', 'slug', 'sort_order', 'status'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 1),
        new OA\Property(property: 'menu_id', type: 'integer', format: 'int64', example: 1),
        new OA\Property(property: 'slug', type: 'string', example: 'starters'),
        new OA\Property(property: 'sort_order', type: 'integer', example: 1),
        new OA\Property(property: 'status', type: 'string', example: 'active'),
        new OA\Property(
            property: 'translations',
            type: 'array',
            items: new OA\Items(ref: '#/components/schemas/Translation')
        ),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'Product',
    required: ['id', 'organization_id', 'internal_name', 'status'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 15),
        new OA\Property(property: 'organization_id', type: 'integer', format: 'int64', example: 1),
        new OA\Property(property: 'sku', type: 'string', example: 'SKU-0001', nullable: true),
        new OA\Property(property: 'internal_name', type: 'string', example: 'Coca-Cola 330ml'),
        new OA\Property(property: 'status', type: 'string', example: 'active'),
        new OA\Property(
            property: 'translations',
            type: 'array',
            items: new OA\Items(ref: '#/components/schemas/Translation')
        ),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'RestaurantProduct',
    required: ['id', 'restaurant_id', 'product_id', 'price', 'available'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 25),
        new OA\Property(property: 'restaurant_id', type: 'integer', format: 'int64', example: 2),
        new OA\Property(property: 'product_id', type: 'integer', format: 'int64', example: 15),
        new OA\Property(property: 'price', type: 'string', example: '12.90'),
        new OA\Property(property: 'available', type: 'boolean', example: true),
        new OA\Property(property: 'product', ref: '#/components/schemas/Product', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'CategoryProduct',
    required: ['id', 'category_id', 'restaurant_product_id', 'sort_order'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 40),
        new OA\Property(property: 'category_id', type: 'integer', format: 'int64', example: 1),
        new OA\Property(property: 'restaurant_product_id', type: 'integer', format: 'int64', example: 25),
        new OA\Property(property: 'sort_order', type: 'integer', example: 10),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'ModifierGroup',
    required: ['id', 'restaurant_product_id', 'internal_name', 'min_select', 'max_select', 'required', 'sort_order', 'status'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 1),
        new OA\Property(property: 'restaurant_product_id', type: 'integer', format: 'int64', example: 25),
        new OA\Property(property: 'internal_name', type: 'string', example: 'Extras'),
        new OA\Property(property: 'min_select', type: 'integer', example: 0),
        new OA\Property(property: 'max_select', type: 'integer', example: 5),
        new OA\Property(property: 'required', type: 'boolean', example: false),
        new OA\Property(property: 'sort_order', type: 'integer', example: 20),
        new OA\Property(property: 'status', type: 'string', example: 'active'),
        new OA\Property(
            property: 'translations',
            type: 'array',
            items: new OA\Items(ref: '#/components/schemas/Translation')
        ),
        new OA\Property(
            property: 'options',
            type: 'array',
            items: new OA\Items(ref: '#/components/schemas/ModifierOption')
        ),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'ModifierOption',
    required: ['id', 'modifier_group_id', 'internal_name', 'price_delta', 'available', 'sort_order', 'status'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 10),
        new OA\Property(property: 'modifier_group_id', type: 'integer', format: 'int64', example: 1),
        new OA\Property(property: 'internal_name', type: 'string', example: 'Bacon'),
        new OA\Property(property: 'price_delta', type: 'string', example: '1.50'),
        new OA\Property(property: 'available', type: 'boolean', example: true),
        new OA\Property(property: 'sort_order', type: 'integer', example: 10),
        new OA\Property(property: 'status', type: 'string', example: 'active'),
        new OA\Property(
            property: 'translations',
            type: 'array',
            items: new OA\Items(ref: '#/components/schemas/Translation')
        ),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'PublicRestaurant',
    required: ['id', 'name', 'default_locale', 'enabled_locales', 'capabilities'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 3),
        new OA\Property(property: 'name', type: 'string', example: 'Aforo Centro'),
        new OA\Property(property: 'default_locale', type: 'string', example: 'es-ES'),
        new OA\Property(property: 'enabled_locales', type: 'array', items: new OA\Items(type: 'string'), example: ['es-ES', 'ca-ES-valencia', 'en-GB']),
        new OA\Property(
            property: 'capabilities',
            description: 'Lets the frontend hide disabled actions. Never includes customer_order_requires_approval — the backend alone decides an order\'s status.',
            properties: [
                new OA\Property(property: 'customer_ordering', type: 'boolean', example: true),
                new OA\Property(property: 'waiter_call', type: 'boolean', example: true),
                new OA\Property(property: 'bill_request', type: 'boolean', example: true),
            ],
            type: 'object'
        ),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'PublicTable',
    required: ['id', 'name', 'number'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 12),
        new OA\Property(property: 'name', type: 'string', example: 'Mesa 12'),
        new OA\Property(property: 'number', type: 'integer', example: 12, nullable: true),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'PublicSessionState',
    required: ['active', 'status'],
    properties: [
        new OA\Property(property: 'active', type: 'boolean', example: true),
        new OA\Property(property: 'status', type: 'string', example: 'occupied', nullable: true),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'PublicTableResolution',
    required: ['restaurant', 'table', 'session', 'menu'],
    properties: [
        new OA\Property(property: 'restaurant', ref: '#/components/schemas/PublicRestaurant'),
        new OA\Property(property: 'table', ref: '#/components/schemas/PublicTable'),
        new OA\Property(property: 'session', ref: '#/components/schemas/PublicSessionState'),
        new OA\Property(
            property: 'menu',
            required: ['available'],
            properties: [
                new OA\Property(property: 'available', type: 'boolean', example: true),
            ],
            type: 'object'
        ),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'PublicModifierOption',
    required: ['id', 'name', 'description', 'price_delta'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 58),
        new OA\Property(property: 'name', type: 'string', example: 'Bacon'),
        new OA\Property(property: 'description', type: 'string', example: null, nullable: true),
        new OA\Property(property: 'price_delta', type: 'string', example: '1.50'),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'PublicModifierGroup',
    required: ['id', 'name', 'description', 'required', 'min_select', 'max_select', 'options'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 21),
        new OA\Property(property: 'name', type: 'string', example: 'Extras'),
        new OA\Property(property: 'description', type: 'string', example: null, nullable: true),
        new OA\Property(property: 'required', type: 'boolean', example: false),
        new OA\Property(property: 'min_select', type: 'integer', example: 0),
        new OA\Property(property: 'max_select', type: 'integer', example: 4),
        new OA\Property(
            property: 'options',
            type: 'array',
            items: new OA\Items(ref: '#/components/schemas/PublicModifierOption')
        ),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'PublicProduct',
    required: ['restaurant_product_id', 'product_id', 'name', 'description', 'price', 'modifier_groups'],
    properties: [
        new OA\Property(property: 'restaurant_product_id', type: 'integer', format: 'int64', example: 100),
        new OA\Property(property: 'product_id', type: 'integer', format: 'int64', example: 15),
        new OA\Property(property: 'name', type: 'string', example: 'Hamburguesa Clásica'),
        new OA\Property(property: 'description', type: 'string', example: 'Carne, queso y salsa', nullable: true),
        new OA\Property(property: 'price', type: 'string', example: '12.90'),
        new OA\Property(
            property: 'modifier_groups',
            type: 'array',
            items: new OA\Items(ref: '#/components/schemas/PublicModifierGroup')
        ),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'PublicCategory',
    required: ['id', 'slug', 'name', 'description', 'products'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 8),
        new OA\Property(property: 'slug', type: 'string', example: 'hamburguesas'),
        new OA\Property(property: 'name', type: 'string', example: 'Hamburguesas'),
        new OA\Property(property: 'description', type: 'string', example: null, nullable: true),
        new OA\Property(
            property: 'products',
            type: 'array',
            items: new OA\Items(ref: '#/components/schemas/PublicProduct')
        ),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'PublicMenu',
    required: ['restaurant', 'table', 'session', 'locale', 'menu'],
    properties: [
        new OA\Property(property: 'restaurant', ref: '#/components/schemas/PublicRestaurant'),
        new OA\Property(property: 'table', ref: '#/components/schemas/PublicTable'),
        new OA\Property(property: 'session', ref: '#/components/schemas/PublicSessionState'),
        new OA\Property(property: 'locale', type: 'string', example: 'es'),
        new OA\Property(
            property: 'menu',
            required: ['id', 'categories'],
            properties: [
                new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 5),
                new OA\Property(
                    property: 'categories',
                    type: 'array',
                    items: new OA\Items(ref: '#/components/schemas/PublicCategory')
                ),
            ],
            type: 'object'
        ),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'PublicApiError',
    required: ['error'],
    properties: [
        new OA\Property(
            property: 'error',
            required: ['code', 'message'],
            properties: [
                new OA\Property(property: 'code', type: 'string', example: 'PUBLIC_TABLE_NOT_FOUND'),
                new OA\Property(property: 'message', type: 'string', example: 'Table not found.'),
            ],
            type: 'object'
        ),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'OrderItemModifier',
    required: ['id', 'modifier_group_id', 'modifier_option_id', 'group_name', 'name', 'price_delta'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 800),
        new OA\Property(property: 'modifier_group_id', type: 'integer', format: 'int64', example: 21, nullable: true),
        new OA\Property(property: 'modifier_option_id', type: 'integer', format: 'int64', example: 58, nullable: true),
        new OA\Property(property: 'group_name', type: 'string', example: 'Extras'),
        new OA\Property(property: 'name', type: 'string', example: 'Bacon'),
        new OA\Property(property: 'price_delta', type: 'string', example: '1.50'),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'OrderItem',
    required: ['id', 'restaurant_product_id', 'product_id', 'name', 'description', 'unit_price', 'quantity', 'modifiers_unit_total', 'unit_total', 'line_total', 'note', 'modifiers'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 501),
        new OA\Property(property: 'restaurant_product_id', type: 'integer', format: 'int64', example: 100),
        new OA\Property(property: 'product_id', type: 'integer', format: 'int64', example: 15, nullable: true),
        new OA\Property(property: 'name', type: 'string', example: 'Hamburguesa Clásica', description: 'Snapshot taken at order time — never the product\'s current name.'),
        new OA\Property(property: 'description', type: 'string', example: 'Carne, queso y salsa', nullable: true),
        new OA\Property(property: 'unit_price', type: 'string', example: '12.90', description: 'Snapshot — never the RestaurantProduct\'s current price.'),
        new OA\Property(property: 'quantity', type: 'integer', example: 2),
        new OA\Property(property: 'modifiers_unit_total', type: 'string', example: '1.50'),
        new OA\Property(property: 'unit_total', type: 'string', example: '14.40'),
        new OA\Property(property: 'line_total', type: 'string', example: '28.80'),
        new OA\Property(property: 'note', type: 'string', example: null, nullable: true),
        new OA\Property(
            property: 'modifiers',
            type: 'array',
            items: new OA\Items(ref: '#/components/schemas/OrderItemModifier')
        ),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'Order',
    required: ['id', 'order_number', 'origin', 'status', 'restaurant', 'table', 'customer_name', 'customer_note', 'subtotal', 'modifiers_total', 'total', 'items'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 1042),
        new OA\Property(property: 'order_number', type: 'string', example: '#1042'),
        new OA\Property(property: 'origin', type: 'string', example: 'customer_qr'),
        new OA\Property(property: 'status', type: 'string', example: 'waiting_approval'),
        new OA\Property(
            property: 'restaurant',
            properties: [
                new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 3),
                new OA\Property(property: 'name', type: 'string', example: 'Aforo Centro'),
            ],
            type: 'object'
        ),
        new OA\Property(
            property: 'table',
            properties: [
                new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 12),
                new OA\Property(property: 'name', type: 'string', example: 'Mesa 12'),
            ],
            type: 'object'
        ),
        new OA\Property(property: 'customer_name', type: 'string', example: 'Carlos', nullable: true),
        new OA\Property(property: 'customer_note', type: 'string', example: null, nullable: true),
        new OA\Property(property: 'subtotal', type: 'string', example: '25.80'),
        new OA\Property(property: 'modifiers_total', type: 'string', example: '3.00'),
        new OA\Property(property: 'total', type: 'string', example: '28.80'),
        new OA\Property(property: 'created_by_user_id', type: 'integer', format: 'int64', example: 5, nullable: true),
        new OA\Property(property: 'approved_by_user_id', type: 'integer', format: 'int64', example: 5, nullable: true),
        new OA\Property(property: 'cancelled_by_user_id', type: 'integer', format: 'int64', example: null, nullable: true),
        new OA\Property(property: 'approved_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'cancelled_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'accepted_by_user_id', type: 'integer', format: 'int64', example: 8, nullable: true),
        new OA\Property(property: 'accepted_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'preparing_by_user_id', type: 'integer', format: 'int64', example: 8, nullable: true),
        new OA\Property(property: 'preparing_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'ready_by_user_id', type: 'integer', format: 'int64', example: 8, nullable: true),
        new OA\Property(property: 'ready_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'served_by_user_id', type: 'integer', format: 'int64', example: 5, nullable: true),
        new OA\Property(property: 'served_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
        new OA\Property(
            property: 'items',
            type: 'array',
            items: new OA\Items(ref: '#/components/schemas/OrderItem')
        ),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'OrderItemCreateRequest',
    required: ['restaurant_product_id', 'quantity'],
    properties: [
        new OA\Property(property: 'restaurant_product_id', type: 'integer', format: 'int64', example: 100),
        new OA\Property(property: 'quantity', type: 'integer', example: 2, minimum: 1, maximum: 50),
        new OA\Property(property: 'note', type: 'string', example: 'Sin cebolla', nullable: true),
        new OA\Property(
            property: 'modifier_option_ids',
            type: 'array',
            items: new OA\Items(type: 'integer', format: 'int64'),
            example: [58, 61]
        ),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'PublicOrderCreateRequest',
    required: ['items'],
    properties: [
        new OA\Property(property: 'customer_name', type: 'string', example: 'Carlos', nullable: true),
        new OA\Property(property: 'locale', type: 'string', example: 'es'),
        new OA\Property(property: 'note', type: 'string', example: 'Sin cebolla', nullable: true),
        new OA\Property(
            property: 'items',
            type: 'array',
            items: new OA\Items(ref: '#/components/schemas/OrderItemCreateRequest')
        ),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'PublicOrderCreated',
    required: ['id', 'order_number', 'status', 'subtotal', 'modifiers_total', 'total', 'items'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 1042),
        new OA\Property(property: 'order_number', type: 'string', example: '#1042'),
        new OA\Property(property: 'status', type: 'string', example: 'waiting_approval'),
        new OA\Property(property: 'subtotal', type: 'string', example: '25.80'),
        new OA\Property(property: 'modifiers_total', type: 'string', example: '3.00'),
        new OA\Property(property: 'total', type: 'string', example: '28.80'),
        new OA\Property(
            property: 'items',
            type: 'array',
            items: new OA\Items(ref: '#/components/schemas/OrderItem')
        ),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'KitchenOrderItemModifier',
    required: ['group_name', 'name'],
    properties: [
        new OA\Property(property: 'group_name', type: 'string', example: 'Extras'),
        new OA\Property(property: 'name', type: 'string', example: 'Bacon'),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'KitchenOrderItem',
    required: ['id', 'name', 'quantity', 'note', 'modifiers'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 501),
        new OA\Property(property: 'name', type: 'string', example: 'Hamburguesa Clásica', description: 'Snapshot — never the product\'s current name.'),
        new OA\Property(property: 'quantity', type: 'integer', example: 2),
        new OA\Property(property: 'note', type: 'string', example: 'Sin cebolla', nullable: true),
        new OA\Property(
            property: 'modifiers',
            type: 'array',
            items: new OA\Items(ref: '#/components/schemas/KitchenOrderItemModifier')
        ),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'KitchenOrder',
    required: ['id', 'status', 'origin', 'restaurant', 'table', 'order_note', 'created_at', 'elapsed_seconds', 'items'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 1042),
        new OA\Property(property: 'status', type: 'string', example: 'preparing'),
        new OA\Property(property: 'origin', type: 'string', example: 'customer_qr'),
        new OA\Property(
            property: 'restaurant',
            properties: [
                new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 3),
                new OA\Property(property: 'name', type: 'string', example: 'Aforo Centro'),
            ],
            type: 'object'
        ),
        new OA\Property(
            property: 'table',
            properties: [
                new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 12),
                new OA\Property(property: 'name', type: 'string', example: 'Mesa 12'),
                new OA\Property(property: 'number', type: 'integer', example: 12, nullable: true),
            ],
            type: 'object'
        ),
        new OA\Property(property: 'order_note', type: 'string', example: null, nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'elapsed_seconds', type: 'integer', example: 300, minimum: 0, description: 'Seconds since created_at. Computed on read, never persisted.'),
        new OA\Property(
            property: 'items',
            type: 'array',
            items: new OA\Items(ref: '#/components/schemas/KitchenOrderItem')
        ),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'PublicTableRequest',
    required: ['id', 'type', 'status', 'created_at'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 500),
        new OA\Property(property: 'type', type: 'string', example: 'call_waiter'),
        new OA\Property(property: 'status', type: 'string', example: 'pending'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'TableRequestActor',
    required: ['id', 'name'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 8),
        new OA\Property(property: 'name', type: 'string', example: 'Carlos'),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'TableRequest',
    required: ['id', 'type', 'status', 'restaurant', 'table', 'note', 'created_at', 'acknowledged_at', 'acknowledged_by', 'completed_at', 'completed_by', 'cancelled_at', 'cancelled_by'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 500),
        new OA\Property(property: 'type', type: 'string', example: 'call_waiter'),
        new OA\Property(property: 'status', type: 'string', example: 'pending'),
        new OA\Property(
            property: 'restaurant',
            properties: [
                new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 3),
                new OA\Property(property: 'name', type: 'string', example: 'Aforo Centro'),
            ],
            type: 'object'
        ),
        new OA\Property(
            property: 'table',
            properties: [
                new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 12),
                new OA\Property(property: 'name', type: 'string', example: 'Mesa 12'),
                new OA\Property(property: 'number', type: 'integer', example: 12, nullable: true),
            ],
            type: 'object'
        ),
        new OA\Property(property: 'note', type: 'string', example: null, nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'acknowledged_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'acknowledged_by', ref: '#/components/schemas/TableRequestActor', nullable: true),
        new OA\Property(property: 'completed_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'completed_by', ref: '#/components/schemas/TableRequestActor', nullable: true),
        new OA\Property(property: 'cancelled_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'cancelled_by', ref: '#/components/schemas/TableRequestActor', nullable: true),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'BillOrderSummary',
    required: ['id', 'status', 'total', 'created_at'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 1042),
        new OA\Property(property: 'status', type: 'string', example: 'served'),
        new OA\Property(property: 'total', type: 'string', example: '28.80'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'PaymentRecordActor',
    required: ['id', 'name'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 7),
        new OA\Property(property: 'name', type: 'string', example: 'Carlos'),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'PaymentRecord',
    required: ['id', 'method', 'amount', 'currency', 'reference', 'note', 'recorded_at', 'recorded_by'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 301),
        new OA\Property(property: 'method', type: 'string', example: 'card'),
        new OA\Property(property: 'amount', type: 'string', example: '28.40'),
        new OA\Property(property: 'currency', type: 'string', example: 'EUR'),
        new OA\Property(property: 'reference', type: 'string', example: 'POS-8292', nullable: true),
        new OA\Property(property: 'note', type: 'string', example: null, nullable: true),
        new OA\Property(property: 'recorded_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'recorded_by', ref: '#/components/schemas/PaymentRecordActor', nullable: true),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'TableSessionBill',
    required: ['table_session_id', 'status', 'payment_status', 'table', 'orders_total', 'paid_total', 'balance', 'can_close', 'orders', 'payments'],
    properties: [
        new OA\Property(property: 'table_session_id', type: 'integer', format: 'int64', example: 88),
        new OA\Property(property: 'status', type: 'string', example: 'occupied'),
        new OA\Property(property: 'payment_status', type: 'string', example: 'unpaid'),
        new OA\Property(
            property: 'table',
            properties: [
                new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 12),
                new OA\Property(property: 'name', type: 'string', example: 'Mesa 12'),
            ],
            type: 'object'
        ),
        new OA\Property(property: 'orders_total', type: 'string', example: '58.40'),
        new OA\Property(property: 'paid_total', type: 'string', example: '30.00'),
        new OA\Property(property: 'balance', type: 'string', example: '28.40'),
        new OA\Property(property: 'can_close', type: 'boolean', example: false),
        new OA\Property(
            property: 'orders',
            type: 'array',
            items: new OA\Items(ref: '#/components/schemas/BillOrderSummary')
        ),
        new OA\Property(
            property: 'payments',
            type: 'array',
            items: new OA\Items(ref: '#/components/schemas/PaymentRecord')
        ),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'CreatePaymentRequest',
    required: ['method', 'amount'],
    properties: [
        new OA\Property(property: 'method', type: 'string', example: 'card', description: 'One of: cash, card, other'),
        new OA\Property(property: 'amount', type: 'string', example: '28.40', description: 'Decimal string, > 0, at most the current balance. Never a JSON number.'),
        new OA\Property(property: 'reference', type: 'string', example: 'POS-8292', nullable: true),
        new OA\Property(property: 'note', type: 'string', example: null, nullable: true),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'KitchenTicket',
    required: ['document_type', 'restaurant', 'order', 'table', 'order_note', 'items', 'generated_at'],
    description: 'Reuses KitchenOrderItem/KitchenOrderItemModifier (Bloco 11) for items — it is the exact same snapshot-only, no-price shape the Kitchen Display already shows on screen.',
    properties: [
        new OA\Property(property: 'document_type', type: 'string', example: 'kitchen_ticket'),
        new OA\Property(property: 'restaurant', ref: '#/components/schemas/PublicRestaurant'),
        new OA\Property(
            property: 'order',
            required: ['id', 'status', 'origin', 'created_at'],
            properties: [
                new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 1042),
                new OA\Property(property: 'status', type: 'string', example: 'confirmed'),
                new OA\Property(property: 'origin', type: 'string', example: 'customer_qr'),
                new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
            ],
            type: 'object'
        ),
        new OA\Property(property: 'table', ref: '#/components/schemas/PublicTable'),
        new OA\Property(property: 'order_note', type: 'string', example: null, nullable: true),
        new OA\Property(
            property: 'items',
            type: 'array',
            items: new OA\Items(ref: '#/components/schemas/KitchenOrderItem')
        ),
        new OA\Property(property: 'generated_at', type: 'string', format: 'date-time', description: 'Computed on read, never persisted.'),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'BillReceiptModifier',
    required: ['name', 'price_delta'],
    properties: [
        new OA\Property(property: 'name', type: 'string', example: 'Bacon'),
        new OA\Property(property: 'price_delta', type: 'string', example: '1.50'),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'BillReceiptItem',
    required: ['name', 'quantity', 'unit_price', 'modifiers', 'line_total'],
    properties: [
        new OA\Property(property: 'name', type: 'string', example: 'Hamburguesa Clásica'),
        new OA\Property(property: 'quantity', type: 'integer', example: 2),
        new OA\Property(property: 'unit_price', type: 'string', example: '10.00'),
        new OA\Property(
            property: 'modifiers',
            type: 'array',
            items: new OA\Items(ref: '#/components/schemas/BillReceiptModifier')
        ),
        new OA\Property(property: 'line_total', type: 'string', example: '23.00'),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'BillReceiptOrder',
    required: ['id', 'total', 'items'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 1042),
        new OA\Property(property: 'total', type: 'string', example: '25.00'),
        new OA\Property(
            property: 'items',
            type: 'array',
            items: new OA\Items(ref: '#/components/schemas/BillReceiptItem')
        ),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'BillReceipt',
    required: ['document_type', 'restaurant', 'table', 'table_session_id', 'opened_at', 'closed_at', 'orders', 'orders_total', 'paid_total', 'balance', 'payment_status', 'payments', 'generated_at'],
    description: 'An operational receipt, not a fiscal document — no VAT breakdown, invoice number, or legal identifiers. Totals are always computed via SessionBillCalculator, identical to GET /table-sessions/{id}/bill.',
    properties: [
        new OA\Property(property: 'document_type', type: 'string', example: 'bill_receipt'),
        new OA\Property(property: 'restaurant', ref: '#/components/schemas/PublicRestaurant'),
        new OA\Property(property: 'table', ref: '#/components/schemas/PublicTable'),
        new OA\Property(property: 'table_session_id', type: 'integer', format: 'int64', example: 88),
        new OA\Property(property: 'opened_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'closed_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(
            property: 'orders',
            type: 'array',
            items: new OA\Items(ref: '#/components/schemas/BillReceiptOrder')
        ),
        new OA\Property(property: 'orders_total', type: 'string', example: '45.00'),
        new OA\Property(property: 'paid_total', type: 'string', example: '0.00'),
        new OA\Property(property: 'balance', type: 'string', example: '45.00'),
        new OA\Property(property: 'payment_status', type: 'string', example: 'unpaid'),
        new OA\Property(
            property: 'payments',
            type: 'array',
            items: new OA\Items(ref: '#/components/schemas/PaymentRecord')
        ),
        new OA\Property(property: 'generated_at', type: 'string', format: 'date-time', description: 'Computed on read, never persisted.'),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'PrintRequestResponse',
    required: ['data'],
    description: 'print_record_id is an audit-trail reference: it means the document was generated/requested for printing, never that a physical printer confirmed the job.',
    properties: [
        new OA\Property(
            property: 'data',
            required: ['print_record_id', 'document'],
            properties: [
                new OA\Property(property: 'print_record_id', type: 'integer', format: 'int64', example: 91),
                new OA\Property(property: 'document', description: 'The KitchenTicket or BillReceipt document, depending on the endpoint.', type: 'object'),
            ],
            type: 'object'
        ),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'StaffPerformanceStaff',
    required: ['id', 'name', 'restaurant'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 42),
        new OA\Property(property: 'name', type: 'string', example: 'Carlos'),
        new OA\Property(
            property: 'restaurant',
            properties: [
                new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 3),
                new OA\Property(property: 'name', type: 'string', example: 'Aforo Centro'),
            ],
            type: 'object',
            nullable: true,
            description: 'null only for an organization-wide caller viewing their own /me/performance — every other case has exactly one restaurant.'
        ),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'PerformancePeriod',
    required: ['from', 'to'],
    properties: [
        new OA\Property(property: 'from', type: 'string', format: 'date', example: '2026-09-01'),
        new OA\Property(property: 'to', type: 'string', format: 'date', example: '2026-09-30', description: 'Inclusive — the underlying query filters as a half-open range internally.'),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'StaffPerformanceMetrics',
    description: 'Objective counts derived from operational history. Never combined into a score.',
    required: ['tables_served', 'orders_created', 'orders_served', 'customer_orders_approved', 'table_requests_handled', 'sessions_closed'],
    properties: [
        new OA\Property(property: 'tables_served', type: 'integer', example: 12, description: 'Distinct table sessions with at least one order served by this staff member.'),
        new OA\Property(property: 'orders_created', type: 'integer', example: 30),
        new OA\Property(property: 'orders_served', type: 'integer', example: 28),
        new OA\Property(property: 'customer_orders_approved', type: 'integer', example: 15),
        new OA\Property(property: 'table_requests_handled', type: 'integer', example: 9, description: 'Only requests actually completed by this staff member — acknowledging alone does not count.'),
        new OA\Property(property: 'sessions_closed', type: 'integer', example: 7),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'StaffRatingSummary',
    description: 'Subjective rating summary, deliberately kept separate from the objective metrics — never merged into a single score.',
    required: ['average', 'review_count'],
    properties: [
        new OA\Property(property: 'average', type: 'string', example: '4.67', nullable: true, description: 'null when review_count is 0, never "0.00".'),
        new OA\Property(property: 'review_count', type: 'integer', example: 3),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'StaffPerformance',
    required: ['staff', 'scope', 'period', 'metrics', 'rating'],
    description: 'Never exposes review comments or reviewer identity — see StaffReview for that, gated behind manage_staff_reviews.',
    properties: [
        new OA\Property(property: 'staff', ref: '#/components/schemas/StaffPerformanceStaff'),
        new OA\Property(property: 'scope', type: 'string', example: 'restaurant', description: '"restaurant" or "organization". Only /me/performance for an organization-wide caller can be "organization".'),
        new OA\Property(property: 'period', ref: '#/components/schemas/PerformancePeriod'),
        new OA\Property(property: 'metrics', ref: '#/components/schemas/StaffPerformanceMetrics'),
        new OA\Property(property: 'rating', ref: '#/components/schemas/StaffRatingSummary'),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'StaffReviewActor',
    required: ['id', 'name'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 7),
        new OA\Property(property: 'name', type: 'string', example: 'Ana'),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'StaffReview',
    required: ['id', 'rating', 'comment', 'reviewer', 'created_at'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 15),
        new OA\Property(property: 'rating', type: 'integer', example: 5),
        new OA\Property(property: 'comment', type: 'string', example: 'Great shift, very attentive.', nullable: true),
        new OA\Property(property: 'reviewer', ref: '#/components/schemas/StaffReviewActor', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'CreateStaffReviewRequest',
    required: ['rating'],
    description: 'organization_id, restaurant_id, staff_user_id and reviewer_user_id are always derived server-side and never accepted from the client, even if present in the body.',
    properties: [
        new OA\Property(property: 'rating', type: 'integer', example: 5, description: 'Integer between 1 and 5.'),
        new OA\Property(property: 'comment', type: 'string', example: 'Great shift, very attentive.', nullable: true, description: 'Up to 1000 characters.'),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'AuditActor',
    description: 'type is always "user", "public" or "system". user is null for "public"/"system" — never a synthetic User.',
    required: ['type', 'user'],
    properties: [
        new OA\Property(property: 'type', type: 'string', example: 'user'),
        new OA\Property(
            property: 'user',
            properties: [
                new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 17),
                new OA\Property(property: 'name', type: 'string', example: 'Carlos'),
            ],
            type: 'object',
            nullable: true
        ),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'AuditRestaurant',
    properties: [
        new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 3),
        new OA\Property(property: 'name', type: 'string', example: 'Aforo Centro'),
    ],
    type: 'object',
    nullable: true
)]
#[OA\Schema(
    schema: 'AuditResource',
    description: 'A logical reference only (resource_type + resource_id) — never a live-loaded copy of the original Model, so the audit log survives even if the referenced row is later deleted.',
    required: ['type', 'id'],
    properties: [
        new OA\Property(property: 'type', type: 'string', example: 'order'),
        new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 412, nullable: true),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'AuditLog',
    required: ['id', 'event', 'actor', 'restaurant', 'resource', 'changes', 'metadata', 'created_at'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 921),
        new OA\Property(property: 'event', type: 'string', example: 'order.served'),
        new OA\Property(property: 'actor', ref: '#/components/schemas/AuditActor'),
        new OA\Property(property: 'restaurant', ref: '#/components/schemas/AuditRestaurant'),
        new OA\Property(property: 'resource', ref: '#/components/schemas/AuditResource'),
        new OA\Property(
            property: 'changes',
            description: 'Explicit old/new whitelist for a small set of events (e.g. staff.updated). null for most events — see metadata instead.',
            type: 'object',
            nullable: true,
            example: null
        ),
        new OA\Property(
            property: 'metadata',
            description: 'Event context — e.g. {previous_status, new_status}. Never comments, references, or other freeform/sensitive fields.',
            properties: [
                new OA\Property(property: 'previous_status', type: 'string', example: 'ready'),
                new OA\Property(property: 'new_status', type: 'string', example: 'served'),
            ],
            type: 'object',
            nullable: true
        ),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'AuditLogPaginationMeta',
    required: ['current_page', 'per_page', 'total', 'last_page'],
    properties: [
        new OA\Property(property: 'current_page', type: 'integer', example: 1),
        new OA\Property(property: 'per_page', type: 'integer', example: 25),
        new OA\Property(property: 'total', type: 'integer', example: 118),
        new OA\Property(property: 'last_page', type: 'integer', example: 5),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'DashboardRestaurant',
    required: ['id', 'name'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 3),
        new OA\Property(property: 'name', type: 'string', example: 'Aforo Centro'),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'DashboardPeriod',
    required: ['from', 'to'],
    properties: [
        new OA\Property(property: 'from', type: 'string', format: 'date', example: '2026-09-01'),
        new OA\Property(property: 'to', type: 'string', format: 'date', example: '2026-09-30', description: 'Inclusive — the underlying query filters as a half-open range internally.'),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'DashboardSales',
    description: 'sales.total is SUM(payment_records.amount) recorded in the period — money actually collected, never Order totals or a fiscal revenue figure.',
    required: ['total', 'average_ticket', 'sessions_with_payments'],
    properties: [
        new OA\Property(property: 'total', type: 'string', example: '1250.00'),
        new OA\Property(property: 'average_ticket', type: 'string', example: '31.25', description: 'total / sessions_with_payments. "0.00" (never null) when sessions_with_payments is 0.'),
        new OA\Property(property: 'sessions_with_payments', type: 'integer', example: 40, description: 'Distinct table sessions with at least one payment recorded in the period — includes partially paid sessions, not only fully-settled ones.'),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'DashboardOrders',
    required: ['created', 'served', 'cancelled', 'customer_qr', 'staff_created'],
    properties: [
        new OA\Property(property: 'created', type: 'integer', example: 95, description: 'Filtered by created_at.'),
        new OA\Property(property: 'served', type: 'integer', example: 88, description: 'Filtered by served_at — may include orders created in an earlier period.'),
        new OA\Property(property: 'cancelled', type: 'integer', example: 4, description: 'status=cancelled, filtered by cancelled_at.'),
        new OA\Property(property: 'customer_qr', type: 'integer', example: 52, description: 'origin=customer_qr, filtered by created_at.'),
        new OA\Property(property: 'staff_created', type: 'integer', example: 43, description: 'origin!=customer_qr, filtered by created_at.'),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'DashboardTables',
    required: ['sessions_opened', 'sessions_closed', 'current_active'],
    properties: [
        new OA\Property(property: 'sessions_opened', type: 'integer', example: 44, description: 'Filtered by opened_at.'),
        new OA\Property(property: 'sessions_closed', type: 'integer', example: 40, description: 'Filtered by closed_at.'),
        new OA\Property(property: 'current_active', type: 'integer', example: 3, description: 'A snapshot of right now (status != closed) — does NOT respect the ?from=/?to= period.'),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'DashboardPaymentMethod',
    required: ['count', 'amount'],
    properties: [
        new OA\Property(property: 'count', type: 'integer', example: 14),
        new OA\Property(property: 'amount', type: 'string', example: '390.00'),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'DashboardPayments',
    required: ['total_records', 'by_method'],
    properties: [
        new OA\Property(property: 'total_records', type: 'integer', example: 43),
        new OA\Property(
            property: 'by_method',
            description: 'Always includes cash/card/other, zero-filled when a method had no records in the period.',
            properties: [
                new OA\Property(property: 'cash', ref: '#/components/schemas/DashboardPaymentMethod'),
                new OA\Property(property: 'card', ref: '#/components/schemas/DashboardPaymentMethod'),
                new OA\Property(property: 'other', ref: '#/components/schemas/DashboardPaymentMethod'),
            ],
            type: 'object'
        ),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'DashboardRequests',
    description: 'Response-time metrics (pending->acknowledged->completed durations) are out of scope for this endpoint.',
    required: ['call_waiter', 'request_bill', 'completed'],
    properties: [
        new OA\Property(property: 'call_waiter', type: 'integer', example: 21, description: 'Filtered by created_at.'),
        new OA\Property(property: 'request_bill', type: 'integer', example: 17, description: 'Filtered by created_at.'),
        new OA\Property(property: 'completed', type: 'integer', example: 35, description: 'status=completed, filtered by completed_at.'),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'DashboardTopStaff',
    required: ['staff', 'orders_served'],
    properties: [
        new OA\Property(
            property: 'staff',
            required: ['id', 'name'],
            properties: [
                new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 10),
                new OA\Property(property: 'name', type: 'string', example: 'Carlos'),
            ],
            type: 'object'
        ),
        new OA\Property(property: 'orders_served', type: 'integer', example: 42),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'DashboardStaff',
    description: 'A purely factual ordering by orders served — never a performance score or rating (see StaffPerformance for that).',
    required: ['top_by_orders_served'],
    properties: [
        new OA\Property(
            property: 'top_by_orders_served',
            type: 'array',
            maxItems: 5,
            items: new OA\Items(ref: '#/components/schemas/DashboardTopStaff')
        ),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'RestaurantDashboard',
    required: ['restaurant', 'period', 'sales', 'orders', 'tables', 'payments', 'requests', 'staff'],
    properties: [
        new OA\Property(property: 'restaurant', ref: '#/components/schemas/DashboardRestaurant'),
        new OA\Property(property: 'period', ref: '#/components/schemas/DashboardPeriod'),
        new OA\Property(property: 'sales', ref: '#/components/schemas/DashboardSales'),
        new OA\Property(property: 'orders', ref: '#/components/schemas/DashboardOrders'),
        new OA\Property(property: 'tables', ref: '#/components/schemas/DashboardTables'),
        new OA\Property(property: 'payments', ref: '#/components/schemas/DashboardPayments'),
        new OA\Property(property: 'requests', ref: '#/components/schemas/DashboardRequests'),
        new OA\Property(property: 'staff', ref: '#/components/schemas/DashboardStaff'),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'RestaurantSettings',
    description: 'A restaurant\'s operational configuration. Money presentation stays server-side-neutral — "12.50"/currency, never localized here.',
    required: [
        'default_locale', 'enabled_locales', 'currency', 'timezone',
        'customer_ordering_enabled', 'customer_order_requires_approval',
        'waiter_call_enabled', 'bill_request_enabled',
        'kitchen_ticket_printing_enabled', 'bill_receipt_printing_enabled',
    ],
    properties: [
        new OA\Property(property: 'default_locale', type: 'string', example: 'es-ES', description: 'One of es-ES / ca-ES-valencia / en-GB. Always a member of enabled_locales.'),
        new OA\Property(property: 'enabled_locales', type: 'array', items: new OA\Items(type: 'string'), example: ['es-ES', 'ca-ES-valencia', 'en-GB']),
        new OA\Property(property: 'currency', type: 'string', example: 'EUR', description: 'ISO 4217. Only EUR is accepted in this MVP.'),
        new OA\Property(property: 'timezone', type: 'string', example: 'Europe/Madrid'),
        new OA\Property(property: 'customer_ordering_enabled', type: 'boolean', example: true),
        new OA\Property(property: 'customer_order_requires_approval', type: 'boolean', example: false, description: 'false: a customer_qr order is auto-confirmed straight into the KDS. true: it waits for waiter approval (the pre-Bloco-18 flow).'),
        new OA\Property(property: 'waiter_call_enabled', type: 'boolean', example: true),
        new OA\Property(property: 'bill_request_enabled', type: 'boolean', example: true),
        new OA\Property(property: 'kitchen_ticket_printing_enabled', type: 'boolean', example: true, description: 'Gates POST .../kitchen-ticket/print only — the GET preview is always available.'),
        new OA\Property(property: 'bill_receipt_printing_enabled', type: 'boolean', example: true, description: 'Gates POST .../receipt/print only — the GET preview is always available.'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'UpdateRestaurantSettingsRequest',
    description: 'Every field is optional (PATCH semantics). organization_id/restaurant_id are never accepted.',
    properties: [
        new OA\Property(property: 'default_locale', type: 'string', example: 'ca-ES-valencia'),
        new OA\Property(property: 'enabled_locales', type: 'array', items: new OA\Items(type: 'string'), example: ['es-ES', 'en-GB']),
        new OA\Property(property: 'currency', type: 'string', example: 'EUR'),
        new OA\Property(property: 'timezone', type: 'string', example: 'Europe/Madrid'),
        new OA\Property(property: 'customer_ordering_enabled', type: 'boolean', example: true),
        new OA\Property(property: 'customer_order_requires_approval', type: 'boolean', example: false),
        new OA\Property(property: 'waiter_call_enabled', type: 'boolean', example: true),
        new OA\Property(property: 'bill_request_enabled', type: 'boolean', example: true),
        new OA\Property(property: 'kitchen_ticket_printing_enabled', type: 'boolean', example: true),
        new OA\Property(property: 'bill_receipt_printing_enabled', type: 'boolean', example: true),
    ],
    type: 'object'
)]
class ApiDocumentation {}
