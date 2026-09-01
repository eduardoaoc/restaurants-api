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
class ApiDocumentation {}
