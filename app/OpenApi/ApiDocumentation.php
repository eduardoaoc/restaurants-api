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
    required: ['id', 'organization_id', 'name', 'slug', 'status', 'timezone', 'default_locale'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 1),
        new OA\Property(property: 'organization_id', type: 'integer', format: 'int64', example: 1),
        new OA\Property(property: 'name', type: 'string', example: 'Downtown Branch'),
        new OA\Property(property: 'slug', type: 'string', example: 'downtown-branch'),
        new OA\Property(property: 'status', type: 'string', example: 'active'),
        new OA\Property(property: 'timezone', type: 'string', example: 'America/Sao_Paulo'),
        new OA\Property(property: 'default_locale', type: 'string', example: 'pt-BR'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'Staff',
    required: ['id', 'name', 'email', 'sub_id', 'role', 'restaurant'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 10),
        new OA\Property(property: 'name', type: 'string', example: 'Carlos'),
        new OA\Property(property: 'email', type: 'string', format: 'email', example: 'carlos@example.com'),
        new OA\Property(property: 'sub_id', type: 'string', example: 'W-023'),
        new OA\Property(
            property: 'role',
            properties: [
                new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 3),
                new OA\Property(property: 'slug', type: 'string', example: 'waiter'),
            ],
            type: 'object'
        ),
        new OA\Property(
            property: 'restaurant',
            properties: [
                new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 2),
                new OA\Property(property: 'name', type: 'string', example: 'Restaurante Centro'),
            ],
            type: 'object'
        ),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
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
        new OA\Property(property: 'opened_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'closed_at', type: 'string', format: 'date-time', nullable: true),
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
    required: ['id', 'name'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 3),
        new OA\Property(property: 'name', type: 'string', example: 'Aforo Centro'),
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
class ApiDocumentation {}
