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
class ApiDocumentation {}
