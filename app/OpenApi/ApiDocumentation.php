<?php

namespace App\OpenApi;

use App\Models\Product;
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
    schema: 'AuthContextUser',
    description: 'The minimal identity fields exposed inside AuthContext — deliberately not a ref to the full User schema, which carries email_verified_at/timestamps this endpoint does not return.',
    required: ['id', 'name', 'email', 'status'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 1),
        new OA\Property(property: 'name', type: 'string', example: 'Example User'),
        new OA\Property(property: 'email', type: 'string', format: 'email', example: 'user@example.com'),
        new OA\Property(property: 'status', type: 'string', example: 'active'),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'AuthContextPlatform',
    description: 'Platform-level access, entirely separate from tenant (organization/restaurant) access. is_platform_admin is the single field the frontend needs to decide whether /platform is reachable at all — never inferred from name/email or from any tenant role.',
    required: ['is_platform_admin', 'roles', 'permissions'],
    properties: [
        new OA\Property(property: 'is_platform_admin', type: 'boolean', example: false),
        new OA\Property(property: 'roles', type: 'array', items: new OA\Items(type: 'string'), example: []),
        new OA\Property(property: 'permissions', type: 'array', items: new OA\Items(type: 'string'), example: []),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'AuthContextRestaurant',
    description: 'One restaurant reachable by the user within an organization. permissions is scoped to THIS restaurant only — an organization-wide role contributes to every restaurant, a restaurant-scoped role contributes only here (see AuthContextBuilder).',
    required: ['id', 'name', 'slug', 'status', 'roles', 'permissions', 'can_manage_table_structure'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 10),
        new OA\Property(property: 'name', type: 'string', example: 'Downtown Branch'),
        new OA\Property(property: 'slug', type: 'string', example: 'downtown-branch'),
        new OA\Property(property: 'status', type: 'string', example: 'active'),
        new OA\Property(property: 'roles', type: 'array', items: new OA\Items(type: 'string'), example: ['manager']),
        new OA\Property(property: 'permissions', type: 'array', items: new OA\Items(type: 'string'), example: ['manage_menu', 'manage_tables', 'view_operations']),
        new OA\Property(property: 'can_manage_table_structure', type: 'boolean', example: true, description: 'Derived capability (CARTA 8.2A), computed by TablePolicy::manageStructure for THIS restaurant: true with manage_floor_plan; otherwise manage_tables AND RestaurantSettings.waiter_table_management_enabled. Governs creating tables and changing name/number/capacity/zone/layout. UX projection only — the API still answers 403 if it changed since this context was fetched; refetch /auth/context to refresh it. Not a persisted permission.'),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'AuthContextOrganization',
    description: 'One organization the user belongs to. permissions here mirrors what User::hasPermission() enforces for organization-scoped actions (create/update restaurants, manage staff, ...) — restaurants[] lists only the restaurants this user can actually reach within it (see RestaurantScope), each with its own narrower permissions array.',
    required: ['id', 'name', 'slug', 'status', 'roles', 'permissions', 'restaurants'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 1),
        new OA\Property(property: 'name', type: 'string', example: 'Grupo Exemplo'),
        new OA\Property(property: 'slug', type: 'string', example: 'grupo-exemplo'),
        new OA\Property(property: 'status', type: 'string', example: 'active'),
        new OA\Property(property: 'roles', type: 'array', items: new OA\Items(type: 'string'), example: ['owner']),
        new OA\Property(property: 'permissions', type: 'array', items: new OA\Items(type: 'string'), example: ['manage_organization', 'manage_restaurants', 'manage_users', 'view_audit']),
        new OA\Property(property: 'restaurants', type: 'array', items: new OA\Items(ref: '#/components/schemas/AuthContextRestaurant')),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'AuthContext',
    description: 'The authenticated user\'s full authorization context — see GET /api/v1/auth/context and AuthContextBuilder. A read projection over existing role/permission relationships: it introduces no new authorization, it only describes ahead of time what the real Policies would currently allow.',
    required: ['user', 'platform', 'organizations'],
    properties: [
        new OA\Property(property: 'user', ref: '#/components/schemas/AuthContextUser'),
        new OA\Property(property: 'platform', ref: '#/components/schemas/AuthContextPlatform'),
        new OA\Property(property: 'organizations', type: 'array', items: new OA\Items(ref: '#/components/schemas/AuthContextOrganization')),
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
    required: ['id', 'name', 'email', 'status', 'role', 'restaurants'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 10),
        new OA\Property(property: 'name', type: 'string', example: 'Carlos'),
        new OA\Property(property: 'email', type: 'string', format: 'email', example: 'carlos@example.com'),
        new OA\Property(
            property: 'status',
            description: 'active or inactive — this staff member\'s OPERATIONAL membership in the active organization (App\Models\OrganizationUser::STATUSES). This is NOT platform-level suspension: it is a tenant-controlled flag, scoped to one organization, that never appears for and never affects the user\'s global account (App\Models\User::status). inactive means the staff member cannot authenticate/operate within THIS organization (login, tenant API requests, Auth Context, and realtime channels of this organization\'s restaurants are all blocked), but roles, restaurant assignments, and history are preserved, and the same user may still be fully active in another organization.',
            type: 'string',
            example: 'active'
        ),
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
        new OA\Property(
            property: 'status',
            description: 'active or inactive (App\Models\OrganizationUser::STATUSES). Deactivates/reactivates the staff member\'s membership in THIS organization only, without touching restaurant assignments, roles, or the user\'s global account status. This endpoint can never read or write platform-level suspension (App\Models\User::status) — that remains exclusively a Platform Admin concern. Self-deactivation is refused.',
            type: 'string',
            example: 'inactive'
        ),
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
        new OA\Property(property: 'capacity', type: 'integer', example: 4, nullable: true, description: 'Number of seats. Purely informational — never validated against guest_count.'),
        new OA\Property(property: 'public_token', type: 'string', example: 'q8dJf83Kp...'),
        new OA\Property(property: 'status', type: 'string', example: 'active'),
        new OA\Property(property: 'zone_id', type: 'integer', format: 'int64', example: 10, nullable: true, description: 'null until assigned a position on the floor plan (Bloco 1).'),
        new OA\Property(
            property: 'layout',
            description: 'Visual/rendering properties only — never physical dimensions. x/y are normalized 0..1 canvas coordinates, resolution-independent.',
            properties: [
                new OA\Property(property: 'x', type: 'number', format: 'float', example: 0.25, nullable: true),
                new OA\Property(property: 'y', type: 'number', format: 'float', example: 0.4, nullable: true),
                new OA\Property(property: 'rotation', type: 'integer', example: 0, minimum: 0, maximum: 359),
                new OA\Property(property: 'shape', type: 'string', example: 'round', description: 'One of: round, square, rectangle.'),
                new OA\Property(property: 'width', type: 'number', format: 'float', example: 80),
                new OA\Property(property: 'height', type: 'number', format: 'float', example: 80),
            ],
            type: 'object'
        ),
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
        new OA\Property(property: 'voided_at', type: 'string', format: 'date-time', nullable: true, description: 'CARTA 9.1A: set when an EMPTY session was voided (POST /table-sessions/{id}/void) instead of served and closed. A voided session has status closed and closed_at = voided_at, but never counts as attended/closed in any metric.'),
        new OA\Property(property: 'voided_by_user_id', type: 'integer', format: 'int64', nullable: true),
        new OA\Property(property: 'void_reason', type: 'string', nullable: true),
        new OA\Property(
            property: 'assigned_waiter',
            properties: [
                new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 12),
                new OA\Property(property: 'name', type: 'string', example: 'Mateo'),
            ],
            type: 'object',
            nullable: true
        ),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'StaffShift',
    required: ['id', 'restaurant_id', 'started_at', 'is_active'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 44),
        new OA\Property(property: 'restaurant_id', type: 'integer', format: 'int64', example: 3),
        new OA\Property(
            property: 'user',
            properties: [
                new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 12),
                new OA\Property(property: 'name', type: 'string', example: 'Mateo'),
            ],
            type: 'object',
            nullable: true
        ),
        new OA\Property(property: 'role', type: 'string', example: 'waiter', nullable: true),
        new OA\Property(property: 'started_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'ended_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'is_active', type: 'boolean', example: true),
        new OA\Property(property: 'started_by_user_id', type: 'integer', format: 'int64', example: 5, nullable: true),
        new OA\Property(property: 'ended_by_user_id', type: 'integer', format: 'int64', example: 5, nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'StaffShiftPaginationMeta',
    required: ['current_page', 'per_page', 'total', 'last_page'],
    properties: [
        new OA\Property(property: 'current_page', type: 'integer', example: 1),
        new OA\Property(property: 'per_page', type: 'integer', example: 25),
        new OA\Property(property: 'total', type: 'integer', example: 42),
        new OA\Property(property: 'last_page', type: 'integer', example: 2),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'WaiterCall',
    required: ['id', 'table_session_id', 'restaurant_id', 'status', 'created_at'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 7),
        new OA\Property(property: 'table_session_id', type: 'integer', format: 'int64', example: 827),
        new OA\Property(property: 'restaurant_id', type: 'integer', format: 'int64', example: 2),
        new OA\Property(property: 'status', type: 'string', example: 'pending'),
        new OA\Property(
            property: 'waiter',
            properties: [
                new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 12),
                new OA\Property(property: 'name', type: 'string', example: 'Mateo'),
            ],
            type: 'object',
            nullable: true
        ),
        new OA\Property(
            property: 'called_by',
            properties: [
                new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 5),
                new OA\Property(property: 'name', type: 'string', example: 'Ana'),
            ],
            type: 'object',
            nullable: true
        ),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'acknowledged_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(
            property: 'acknowledged_by',
            properties: [
                new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 12),
                new OA\Property(property: 'name', type: 'string', example: 'Mateo'),
            ],
            type: 'object',
            nullable: true
        ),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'Floor',
    required: ['id', 'restaurant_id', 'name', 'sort_order', 'is_active'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 1),
        new OA\Property(property: 'restaurant_id', type: 'integer', format: 'int64', example: 2),
        new OA\Property(property: 'name', type: 'string', example: 'Ground Floor'),
        new OA\Property(property: 'sort_order', type: 'integer', example: 0),
        new OA\Property(property: 'is_active', type: 'boolean', example: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'Zone',
    required: ['id', 'restaurant_id', 'floor_id', 'name', 'sort_order', 'is_active'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 10),
        new OA\Property(property: 'restaurant_id', type: 'integer', format: 'int64', example: 2),
        new OA\Property(property: 'floor_id', type: 'integer', format: 'int64', example: 1),
        new OA\Property(property: 'name', type: 'string', example: 'Interior'),
        new OA\Property(property: 'sort_order', type: 'integer', example: 0),
        new OA\Property(property: 'is_active', type: 'boolean', example: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'FloorPlanZone',
    required: ['id', 'name', 'sort_order', 'is_active', 'tables'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 10),
        new OA\Property(property: 'name', type: 'string', example: 'Interior'),
        new OA\Property(property: 'sort_order', type: 'integer', example: 0),
        new OA\Property(property: 'is_active', type: 'boolean', example: true),
        new OA\Property(property: 'tables', type: 'array', items: new OA\Items(ref: '#/components/schemas/Table')),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'FloorPlanFloor',
    required: ['id', 'name', 'sort_order', 'is_active', 'zones'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 1),
        new OA\Property(property: 'name', type: 'string', example: 'Ground Floor'),
        new OA\Property(property: 'sort_order', type: 'integer', example: 0),
        new OA\Property(property: 'is_active', type: 'boolean', example: true),
        new OA\Property(property: 'zones', type: 'array', items: new OA\Items(ref: '#/components/schemas/FloorPlanZone')),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'FloorPlan',
    description: 'The full editor-ready floor plan of a restaurant. unassigned_tables holds every table with no zone_id yet (e.g. every pre-existing table right after Bloco 1 ships).',
    required: ['restaurant_id', 'floors', 'unassigned_tables'],
    properties: [
        new OA\Property(property: 'restaurant_id', type: 'integer', format: 'int64', example: 2),
        new OA\Property(property: 'floors', type: 'array', items: new OA\Items(ref: '#/components/schemas/FloorPlanFloor')),
        new OA\Property(property: 'unassigned_tables', type: 'array', items: new OA\Items(ref: '#/components/schemas/Table')),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'TableLayoutInput',
    description: 'Every field besides id is optional (PATCH-per-item semantics) — send only what changed for that table.',
    required: ['id'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 1),
        new OA\Property(property: 'zone_id', type: 'integer', format: 'int64', example: 10, nullable: true),
        new OA\Property(property: 'layout_x', type: 'number', format: 'float', example: 0.25),
        new OA\Property(property: 'layout_y', type: 'number', format: 'float', example: 0.4),
        new OA\Property(property: 'layout_rotation', type: 'integer', example: 0, minimum: 0, maximum: 359),
        new OA\Property(property: 'layout_shape', type: 'string', example: 'round'),
        new OA\Property(property: 'layout_width', type: 'number', format: 'float', example: 120),
        new OA\Property(property: 'layout_height', type: 'number', format: 'float', example: 120),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'UpdateFloorPlanLayoutRequest',
    description: 'Rejected as a whole (422, nothing persisted) if any table id or zone_id does not belong to this restaurant.',
    required: ['tables'],
    properties: [
        new OA\Property(
            property: 'tables',
            type: 'array',
            minItems: 1,
            items: new OA\Items(ref: '#/components/schemas/TableLayoutInput')
        ),
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
    schema: 'ProductTranslationInput',
    description: 'Unlike Category/ModifierGroup/ModifierOption translations, a Product translation always requires a non-blank description — it is the public menu\'s dish blurb.',
    required: ['locale', 'name', 'description'],
    properties: [
        new OA\Property(property: 'locale', type: 'string', example: 'es-ES'),
        new OA\Property(property: 'name', type: 'string', example: 'Hamburguesa AFORO'),
        new OA\Property(property: 'description', type: 'string', maxLength: 500, example: 'Carne de vacuno, queso cheddar, tomate, lechuga y salsa de la casa.'),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'NutritionInput',
    description: 'Optional per-serving nutrition values. Omit the whole object, or send it as null, when nothing has been recorded. Every value inside it is independently optional.',
    properties: [
        new OA\Property(property: 'calories_kcal', type: 'integer', minimum: 0, example: 720, nullable: true),
        new OA\Property(property: 'protein_g', type: 'number', format: 'float', minimum: 0, example: 38, nullable: true),
        new OA\Property(property: 'carbohydrates_g', type: 'number', format: 'float', minimum: 0, example: 54, nullable: true),
        new OA\Property(property: 'fat_g', type: 'number', format: 'float', minimum: 0, example: 39, nullable: true),
        new OA\Property(property: 'salt_g', type: 'number', format: 'float', minimum: 0, example: 2.1, nullable: true),
    ],
    type: 'object',
    nullable: true
)]
#[OA\Schema(
    schema: 'Nutrition',
    description: 'Per-serving (never per-100g) nutritional information. The whole object is absent/null when no value has ever been recorded — an individual field can still be null within it.',
    required: ['basis'],
    properties: [
        new OA\Property(property: 'basis', type: 'string', example: 'per_serving'),
        new OA\Property(property: 'calories_kcal', type: 'integer', example: 720, nullable: true),
        new OA\Property(property: 'protein_g', type: 'string', example: '38.00', nullable: true),
        new OA\Property(property: 'carbohydrates_g', type: 'string', example: '54.00', nullable: true),
        new OA\Property(property: 'fat_g', type: 'string', example: '39.00', nullable: true),
        new OA\Property(property: 'salt_g', type: 'string', example: '2.10', nullable: true),
    ],
    type: 'object',
    nullable: true
)]
#[OA\Schema(
    schema: 'ProductMedia',
    description: 'Never exposes the storage disk or internal path — only a ready-to-use, publicly reachable URL (see ProductMediaResource).',
    required: ['id', 'type', 'url', 'mime_type', 'size_bytes'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 1),
        new OA\Property(property: 'type', type: 'string', enum: ['image', 'video'], example: 'image'),
        new OA\Property(property: 'url', type: 'string', example: 'http://localhost:8080/storage/products/1/15/image/9c1f2b8e-....webp'),
        new OA\Property(property: 'mime_type', type: 'string', example: 'image/webp'),
        new OA\Property(property: 'size_bytes', type: 'integer', example: 123456),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'ProductMediaSet',
    description: 'A product\'s media slots. Media is never required for public eligibility (Carta 4.2 rules alone decide that) — both slots are commonly null.',
    required: ['image', 'video'],
    properties: [
        new OA\Property(property: 'image', ref: '#/components/schemas/ProductMedia', nullable: true),
        new OA\Property(property: 'video', ref: '#/components/schemas/ProductMedia', nullable: true),
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
            property: 'allergens',
            description: 'null = allergen declaration not made yet (legacy data, not publicly eligible). [] = declared explicitly as "none". Never confuse the two.',
            type: 'array',
            items: new OA\Items(type: 'string', enum: Product::ALLERGEN_CODES),
            example: ['gluten', 'milk', 'eggs'],
            nullable: true
        ),
        new OA\Property(property: 'nutrition', ref: '#/components/schemas/Nutrition', nullable: true),
        new OA\Property(property: 'media', ref: '#/components/schemas/ProductMediaSet'),
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
    required: ['active', 'status', 'bill_request', 'feedback'],
    properties: [
        new OA\Property(property: 'active', type: 'boolean', example: true),
        new OA\Property(property: 'status', type: 'string', example: 'occupied', nullable: true),
        new OA\Property(
            property: 'bill_request',
            description: 'Backend-derived, presentation-only state of the "request the bill" CTA for the current visit (see ResolvePublicBillRequestStateAction). The client shows the CTA only when restaurant.capabilities.bill_request AND session.bill_request.eligible — the restaurant feature flag is deliberately NOT folded in here. NOT authoritative: POST /public/tables/{publicToken}/requests/bill re-validates everything and decides the real outcome. `reason` is null exactly when eligible=true; otherwise the first match of: no_active_session (no active session) > already_paid (session paid) > already_requested (a request_bill is pending/acknowledged) > open_orders (an order is still waiting approval/in the kitchen/being delivered) > no_billable_orders (nothing consumed yet). This priority intentionally differs from the POST\'s error order: once the bill has been requested, already_requested wins even if staff later add an order that is still open. Unrelated to call_waiter — eligible=false never means the waiter cannot be called.',
            required: ['eligible', 'reason'],
            properties: [
                new OA\Property(property: 'eligible', type: 'boolean', example: false),
                new OA\Property(property: 'reason', type: 'string', enum: ['no_active_session', 'already_paid', 'already_requested', 'open_orders', 'no_billable_orders', null], example: 'open_orders', nullable: true),
            ],
            type: 'object'
        ),
        new OA\Property(
            property: 'feedback',
            description: 'Present for the whole lifetime of the table\'s active session, unpaid or paid — the token is minted at session-open, not at payment, precisely so the client can persist it well before any payment happens (see PublicSessionStateResource / OpenTableAction). `eligible` alone reflects whether the visit is paid yet and is backend-authoritative: holding `token` while eligible=false does NOT let POST /public/feedback/{token} succeed early — SubmitPublicFeedbackAction re-checks payment_status on every call. Absent (only `eligible: false`, no `token`) when the table has no active session at all.',
            required: ['eligible'],
            properties: [
                new OA\Property(property: 'eligible', type: 'boolean', example: true),
                new OA\Property(property: 'token', type: 'string', example: 'K7pQ...', nullable: true),
                new OA\Property(property: 'already_submitted', type: 'boolean', example: false, nullable: true),
            ],
            type: 'object'
        ),
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
    description: 'Never appears without a valid description and an explicit (possibly empty) allergens declaration — see BuildPublicMenuAction eligibility rules. Media (image/video) is never required for eligibility.',
    required: ['restaurant_product_id', 'product_id', 'name', 'description', 'price', 'allergens', 'media', 'modifier_groups'],
    properties: [
        new OA\Property(property: 'restaurant_product_id', type: 'integer', format: 'int64', example: 100),
        new OA\Property(property: 'product_id', type: 'integer', format: 'int64', example: 15),
        new OA\Property(property: 'name', type: 'string', example: 'Hamburguesa Clásica'),
        new OA\Property(property: 'description', type: 'string', example: 'Carne, queso y salsa', nullable: true),
        new OA\Property(property: 'price', type: 'string', example: '12.90'),
        new OA\Property(
            property: 'allergens',
            type: 'array',
            items: new OA\Items(type: 'string', enum: Product::ALLERGEN_CODES),
            example: ['gluten', 'milk', 'eggs']
        ),
        new OA\Property(property: 'nutrition', ref: '#/components/schemas/Nutrition', nullable: true),
        new OA\Property(property: 'media', ref: '#/components/schemas/ProductMediaSet'),
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
    required: ['id', 'order_number', 'status', 'origin', 'restaurant', 'table', 'order_note', 'created_at', 'elapsed_seconds', 'items'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 1042),
        new OA\Property(property: 'order_number', type: 'string', example: '#1042'),
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
    schema: 'PublicFeedbackContext',
    required: ['already_submitted', 'restaurant', 'table'],
    description: 'Minimal context for the public feedback form — never exposes internal ids, the table\'s public_token, financial data, or staff identities.',
    properties: [
        new OA\Property(property: 'already_submitted', type: 'boolean', example: false),
        new OA\Property(
            property: 'restaurant',
            required: ['name'],
            properties: [new OA\Property(property: 'name', type: 'string', example: 'Casa Pepe')],
            type: 'object'
        ),
        new OA\Property(
            property: 'table',
            required: ['name'],
            properties: [new OA\Property(property: 'name', type: 'string', example: 'Mesa 12')],
            type: 'object'
        ),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'PublicVisit',
    required: ['restaurant', 'table', 'visit', 'orders', 'summary', 'google_review'],
    description: 'Post-payment visit summary resolved from the visit\'s feedback_token. Only billable orders, rendered from their persisted snapshots — never the current catalog. Never exposes internal ids, payment records/methods, staff identities or notes.',
    properties: [
        new OA\Property(
            property: 'restaurant',
            required: ['name'],
            properties: [new OA\Property(property: 'name', type: 'string', example: 'Casa Pepe')],
            type: 'object'
        ),
        new OA\Property(
            property: 'table',
            required: ['name'],
            properties: [new OA\Property(property: 'name', type: 'string', example: 'Mesa 12')],
            type: 'object'
        ),
        new OA\Property(
            property: 'visit',
            required: ['active', 'paid_at', 'closed_at'],
            properties: [
                new OA\Property(property: 'active', type: 'boolean', example: false),
                new OA\Property(property: 'paid_at', type: 'string', format: 'date-time', example: '2026-09-24T21:10:00.000000Z'),
                new OA\Property(property: 'closed_at', type: 'string', format: 'date-time', example: '2026-09-24T21:15:00.000000Z', nullable: true),
            ],
            type: 'object'
        ),
        new OA\Property(property: 'orders', type: 'array', items: new OA\Items(ref: '#/components/schemas/PublicVisitOrder')),
        new OA\Property(
            property: 'summary',
            required: ['total'],
            properties: [new OA\Property(property: 'total', type: 'string', example: '17.80', description: 'Sum of billable order totals — same rule as the internal bill (SessionBillCalculator).')],
            type: 'object'
        ),
        new OA\Property(
            property: 'google_review',
            description: 'Whether the restaurant configured a Google Review link (settings.google_review_url). available=false ⇔ url=null; available=true ⇔ url is the stored HTTPS Google review/share link (a format currently supported by AFORO). Derived only from that setting — never from feedback ratings nor from whether feedback was submitted (no review gating); the client decides when to show the CTA.',
            required: ['available', 'url'],
            properties: [
                new OA\Property(property: 'available', type: 'boolean', example: true),
                new OA\Property(property: 'url', type: 'string', format: 'uri', example: 'https://g.page/r/CabcdEFGhij123/review', nullable: true),
            ],
            type: 'object'
        ),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'PublicVisitOrder',
    required: ['order_number', 'created_at', 'total', 'items'],
    properties: [
        new OA\Property(property: 'order_number', type: 'string', example: '#42'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', example: '2026-09-24T20:30:00.000000Z'),
        new OA\Property(property: 'total', type: 'string', example: '17.80'),
        new OA\Property(property: 'items', type: 'array', items: new OA\Items(ref: '#/components/schemas/PublicVisitOrderItem')),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'PublicVisitOrderItem',
    required: ['name', 'quantity', 'unit_price', 'modifiers', 'line_total'],
    properties: [
        new OA\Property(property: 'name', type: 'string', example: 'Hamburguesa'),
        new OA\Property(property: 'quantity', type: 'integer', example: 2),
        new OA\Property(property: 'unit_price', type: 'string', example: '7.90', description: 'Base unit price snapshot, before modifiers'),
        new OA\Property(
            property: 'modifiers',
            type: 'array',
            items: new OA\Items(
                required: ['group_name', 'name', 'price_delta'],
                properties: [
                    new OA\Property(property: 'group_name', type: 'string', example: 'Extras'),
                    new OA\Property(property: 'name', type: 'string', example: 'Bacon'),
                    new OA\Property(property: 'price_delta', type: 'string', example: '1.00'),
                ],
                type: 'object'
            )
        ),
        new OA\Property(property: 'line_total', type: 'string', example: '17.80'),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'CreatePublicFeedbackRequest',
    required: ['first_name', 'last_name', 'wait_time_rating', 'food_rating', 'service_rating', 'overall_rating'],
    properties: [
        new OA\Property(property: 'first_name', type: 'string', example: 'Ana'),
        new OA\Property(property: 'last_name', type: 'string', example: 'García'),
        new OA\Property(property: 'wait_time_rating', type: 'integer', example: 4, description: '1-5'),
        new OA\Property(property: 'food_rating', type: 'integer', example: 5, description: '1-5'),
        new OA\Property(property: 'service_rating', type: 'integer', example: 5, description: '1-5'),
        new OA\Property(property: 'overall_rating', type: 'integer', example: 5, description: '1-5'),
        new OA\Property(property: 'experience_comment', type: 'string', example: 'Great evening.', nullable: true),
        new OA\Property(property: 'improvement_comment', type: 'string', example: 'Faster drinks next time.', nullable: true),
        new OA\Property(property: 'contact', type: 'string', example: 'ana@example.com', nullable: true),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'PublicFeedback',
    description: 'Confirmation echoed back to the customer who just submitted (or replayed) their own feedback.',
    required: ['first_name', 'last_name', 'wait_time_rating', 'food_rating', 'service_rating', 'overall_rating', 'submitted_at'],
    properties: [
        new OA\Property(property: 'first_name', type: 'string', example: 'Ana'),
        new OA\Property(property: 'last_name', type: 'string', example: 'García'),
        new OA\Property(property: 'wait_time_rating', type: 'integer', example: 4),
        new OA\Property(property: 'food_rating', type: 'integer', example: 5),
        new OA\Property(property: 'service_rating', type: 'integer', example: 5),
        new OA\Property(property: 'overall_rating', type: 'integer', example: 5),
        new OA\Property(property: 'experience_comment', type: 'string', example: 'Great evening.', nullable: true),
        new OA\Property(property: 'improvement_comment', type: 'string', example: 'Faster drinks next time.', nullable: true),
        new OA\Property(property: 'contact', type: 'string', example: 'ana@example.com', nullable: true),
        new OA\Property(property: 'submitted_at', type: 'string', format: 'date-time'),
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
            required: ['id', 'order_number', 'status', 'origin', 'created_at'],
            properties: [
                new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 1042),
                new OA\Property(property: 'order_number', type: 'string', example: '#1042'),
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
    schema: 'CustomerFeedbackTable',
    required: ['id', 'name', 'number'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 12),
        new OA\Property(property: 'name', type: 'string', example: 'Mesa 12'),
        new OA\Property(property: 'number', type: 'integer', example: 12, nullable: true),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'CustomerFeedbackWaiter',
    required: ['id', 'name'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 7),
        new OA\Property(property: 'name', type: 'string', example: 'Carlos'),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'CustomerFeedbackListItem',
    description: 'Minimal listing row (Passo 3.5) — no comments/contact. See CustomerFeedback for the full detail.',
    required: ['id', 'submitted_at', 'customer_name', 'table', 'overall_rating', 'food_rating', 'service_rating', 'wait_time_rating', 'waiter'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 41),
        new OA\Property(property: 'submitted_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'customer_name', type: 'string', example: 'Ana García'),
        new OA\Property(property: 'table', ref: '#/components/schemas/CustomerFeedbackTable'),
        new OA\Property(property: 'overall_rating', type: 'integer', example: 5),
        new OA\Property(property: 'food_rating', type: 'integer', example: 5),
        new OA\Property(property: 'service_rating', type: 'integer', example: 5),
        new OA\Property(property: 'wait_time_rating', type: 'integer', example: 4),
        new OA\Property(property: 'waiter', ref: '#/components/schemas/CustomerFeedbackWaiter', nullable: true),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'CustomerFeedback',
    description: 'Full customer feedback detail, including PII (first_name/last_name/contact) and free-text comments. Gated by view_customer_feedback (owner/manager only).',
    required: ['id', 'restaurant', 'table_session', 'waiter', 'first_name', 'last_name', 'wait_time_rating', 'food_rating', 'service_rating', 'overall_rating', 'experience_comment', 'improvement_comment', 'contact', 'submitted_at'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 41),
        new OA\Property(
            property: 'restaurant',
            required: ['id', 'name'],
            properties: [
                new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 3),
                new OA\Property(property: 'name', type: 'string', example: 'Casa Pepe'),
            ],
            type: 'object'
        ),
        new OA\Property(
            property: 'table_session',
            required: ['id', 'table', 'opened_at', 'closed_at'],
            properties: [
                new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 88),
                new OA\Property(property: 'table', ref: '#/components/schemas/CustomerFeedbackTable'),
                new OA\Property(property: 'opened_at', type: 'string', format: 'date-time'),
                new OA\Property(property: 'closed_at', type: 'string', format: 'date-time', nullable: true),
            ],
            type: 'object'
        ),
        new OA\Property(property: 'waiter', ref: '#/components/schemas/CustomerFeedbackWaiter', nullable: true),
        new OA\Property(property: 'first_name', type: 'string', example: 'Ana'),
        new OA\Property(property: 'last_name', type: 'string', example: 'García'),
        new OA\Property(property: 'wait_time_rating', type: 'integer', example: 4),
        new OA\Property(property: 'food_rating', type: 'integer', example: 5),
        new OA\Property(property: 'service_rating', type: 'integer', example: 5),
        new OA\Property(property: 'overall_rating', type: 'integer', example: 5),
        new OA\Property(property: 'experience_comment', type: 'string', example: 'Great evening.', nullable: true),
        new OA\Property(property: 'improvement_comment', type: 'string', example: 'Faster drinks next time.', nullable: true),
        new OA\Property(property: 'contact', type: 'string', example: 'ana@example.com', nullable: true),
        new OA\Property(property: 'submitted_at', type: 'string', format: 'date-time'),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'CustomerFeedbackSummary',
    description: 'Aggregate-only — a waiter\'s own numbers, or an owner/manager consulting one staff member\'s numbers. NEVER individual feedback rows or PII.',
    required: ['feedback_count', 'average_overall', 'average_service', 'average_wait_time', 'average_food'],
    properties: [
        new OA\Property(property: 'feedback_count', type: 'integer', example: 12),
        new OA\Property(property: 'average_overall', type: 'number', format: 'float', example: 4.58, nullable: true, description: 'null when feedback_count is 0.'),
        new OA\Property(property: 'average_service', type: 'number', format: 'float', example: 4.75, nullable: true),
        new OA\Property(property: 'average_wait_time', type: 'number', format: 'float', example: 4.2, nullable: true),
        new OA\Property(property: 'average_food', type: 'number', format: 'float', example: 4.6, nullable: true),
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
        'google_review_url', 'waiter_table_management_enabled',
        'business_day_cutoff_time', 'default_opening_float', 'cash_difference_note_threshold',
        'accept_delay_threshold_minutes', 'preparation_delay_threshold_minutes', 'ready_pickup_delay_threshold_minutes',
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
        new OA\Property(property: 'google_review_url', type: 'string', format: 'uri', maxLength: 2048, example: 'https://g.page/r/CabcdEFGhij123/review', nullable: true, description: 'HTTPS Google review/share link for this restaurant; null = Google Review disabled (there is no separate enabled flag). Must match one of the Google review/share URL formats currently supported by AFORO — not a normative list published by Google: g.page, maps.app.goo.gl, search.google.com (/local/writereview, /local/reviews), www.google.com / google.com (/maps...), maps.google.com (root or /maps...). Maps links are accepted as Google/Maps links and are not guaranteed to open the review form directly; the recommended link is the one from Business Profile → Read reviews → Get more reviews → Copy. The scheme is matched case-insensitively and stored lowercase; the rest of the URL is stored verbatim. Validated structurally only — never fetched or redirect-resolved server-side.'),
        new OA\Property(property: 'waiter_table_management_enabled', type: 'boolean', default: true, example: true, description: 'Whether staff holding manage_tables but NOT manage_floor_plan (waiters) may change the table structure: POST /restaurants/{restaurant}/tables and PATCH /tables/{table} name/number/capacity. false = 403 for them; users holding manage_floor_plan (owner/manager) are never affected. Viewing, QR resolution, status, sessions, orders, bills and requests are never gated by it. Defaults to true (pre-existing behavior).'),
        new OA\Property(property: 'business_day_cutoff_time', type: 'string', pattern: '^([01]\\d|2[0-3]):[0-5]\\d$', default: '06:00', example: '06:00', description: 'CARTA 9.1A — local HH:MM. A Cierre Diario whose local time is before it belongs to the previous business date.'),
        new OA\Property(property: 'default_opening_float', type: 'string', nullable: true, example: '150.00', description: 'Opening float used when the previous close left no cash_left_for_next_day (e.g. the first close). null = the closer must state it.'),
        new OA\Property(property: 'cash_difference_note_threshold', type: 'string', default: '5.00', example: '5.00', description: 'A cash_difference_note is required when |cash difference| is strictly greater than this.'),
        new OA\Property(property: 'accept_delay_threshold_minutes', type: 'integer', default: 10, minimum: 1, maximum: 240),
        new OA\Property(property: 'preparation_delay_threshold_minutes', type: 'integer', default: 30, minimum: 1, maximum: 240),
        new OA\Property(property: 'ready_pickup_delay_threshold_minutes', type: 'integer', default: 10, minimum: 1, maximum: 240),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'CashMovement',
    description: 'A cash drawer pay-in/pay-out (CARTA 9.1A). Append-only.',
    properties: [
        new OA\Property(property: 'id', type: 'integer', format: 'int64'),
        new OA\Property(property: 'restaurant_id', type: 'integer', format: 'int64'),
        new OA\Property(property: 'type', type: 'string', enum: ['pay_in', 'pay_out']),
        new OA\Property(property: 'amount', type: 'string', example: '20.00'),
        new OA\Property(property: 'reason', type: 'string'),
        new OA\Property(property: 'recorded_by', properties: [new OA\Property(property: 'id', type: 'integer', nullable: true), new OA\Property(property: 'name', type: 'string')], type: 'object'),
        new OA\Property(property: 'recorded_at', type: 'string', format: 'date-time'),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'DayCloseAnnotation',
    description: 'A post-close note on a Cierre Diario. Never part of the report/hash.',
    properties: [
        new OA\Property(property: 'id', type: 'integer', format: 'int64'),
        new OA\Property(property: 'body', type: 'string', description: 'Plain text.'),
        new OA\Property(property: 'created_by', properties: [new OA\Property(property: 'id', type: 'integer', nullable: true), new OA\Property(property: 'name', type: 'string')], type: 'object'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'DayClosePeriod',
    properties: [
        new OA\Property(property: 'business_date', type: 'string', format: 'date', example: '2026-10-02'),
        new OA\Property(property: 'business_date_from', type: 'string', format: 'date', description: 'Earlier than business_date when the period covers days nobody closed.'),
        new OA\Property(property: 'period_started_at', type: 'string', format: 'date-time', description: 'Previous close period_ended_at; on the first close, the cutoff of business_date.'),
        new OA\Property(property: 'period_ended_at', type: 'string', format: 'date-time', description: 'Exclusive end (T).'),
        new OA\Property(property: 'timezone', type: 'string', example: 'Europe/Madrid'),
        new OA\Property(property: 'first_close', type: 'boolean', description: 'true: no previous close — activity before period_started_at is outside the closing system.'),
        new OA\Property(property: 'covers_multiple_business_days', type: 'boolean'),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'DayCloseSections',
    description: 'Shared live/snapshot sections of a Cierre Diario over [period_started_at, period_ended_at). Money = decimal strings; instants = UTC ISO-8601.',
    properties: [
        new OA\Property(property: 'financial', description: 'Money actually received (PaymentRecord.recorded_at in the period), never Order totals.', properties: [
            new OA\Property(property: 'currency', type: 'string', example: 'EUR'),
            new OA\Property(property: 'total_received', type: 'string', example: '1842.50'),
            new OA\Property(property: 'by_method', properties: [
                new OA\Property(property: 'cash', type: 'string'), new OA\Property(property: 'card', type: 'string'), new OA\Property(property: 'other', type: 'string'),
            ], type: 'object'),
            new OA\Property(property: 'payments_count', type: 'integer'),
            new OA\Property(property: 'sessions_with_payments', type: 'integer'),
            new OA\Property(property: 'average_ticket', type: 'string', description: 'total_received / sessions_with_payments.'),
        ], type: 'object'),
        new OA\Property(property: 'operations', properties: [
            new OA\Property(property: 'orders', properties: [
                new OA\Property(property: 'registered', type: 'integer', description: 'created in the period, any status'),
                new OA\Property(property: 'valid', type: 'integer', description: 'created in the period and billable (excludes waiting_approval and rejected)'),
                new OA\Property(property: 'served', type: 'integer', description: 'served_at in the period'),
                new OA\Property(property: 'rejected', type: 'integer', description: 'rejected (cancelled_at) in the period'),
            ], type: 'object'),
            new OA\Property(property: 'sessions', properties: [
                new OA\Property(property: 'opened', type: 'integer', description: 'excludes voided'),
                new OA\Property(property: 'closed', type: 'integer', description: 'real closes only, excludes voided'),
                new OA\Property(property: 'voided', type: 'integer'),
            ], type: 'object'),
            new OA\Property(property: 'guests', type: 'integer', description: 'SUM(guest_count) of sessions really closed in the period'),
            new OA\Property(property: 'peak_hour', nullable: true, properties: [
                new OA\Property(property: 'local_hour', type: 'string', example: '2026-10-02T21:00', description: 'Local calendar hour (date + hour).'),
                new OA\Property(property: 'sessions_started', type: 'integer'),
            ], type: 'object'),
        ], type: 'object'),
        new OA\Property(property: 'products', properties: [
            new OA\Property(property: 'top', type: 'array', description: 'Top 5 by quantity, billable orders, OrderItem name snapshot.', items: new OA\Items(properties: [
                new OA\Property(property: 'product_id', type: 'integer', nullable: true), new OA\Property(property: 'name', type: 'string'), new OA\Property(property: 'quantity', type: 'integer'),
            ], type: 'object')),
        ], type: 'object'),
        new OA\Property(property: 'product_availability', description: '"Producto marcado como no disponible" (not stock).', properties: [
            new OA\Property(property: 'count', type: 'integer', description: 'distinct products'),
            new OA\Property(property: 'items', type: 'array', items: new OA\Items(properties: [
                new OA\Property(property: 'restaurant_product_id', type: 'integer'),
                new OA\Property(property: 'product_name_snapshot', type: 'string', nullable: true),
                new OA\Property(property: 'unavailable_since_known', type: 'boolean', description: 'false = "inicio desconocido" (no recorded event).'),
                new OA\Property(property: 'unavailable_at', type: 'string', format: 'date-time', nullable: true),
                new OA\Property(property: 'unavailable_by_name', type: 'string', nullable: true),
                new OA\Property(property: 'available_again_at', type: 'string', format: 'date-time', nullable: true),
                new OA\Property(property: 'available_again_by_name', type: 'string', nullable: true),
                new OA\Property(property: 'duration_seconds', type: 'integer', nullable: true),
                new OA\Property(property: 'state_at_close', type: 'string', enum: ['available', 'unavailable']),
            ], type: 'object')),
        ], type: 'object'),
        new OA\Property(property: 'feedback', description: 'By submitted_at in the period. No customer PII (no names, no contact).', properties: [
            new OA\Property(property: 'count', type: 'integer'),
            new OA\Property(property: 'avg_overall', type: 'string', nullable: true, example: '4.33'),
            new OA\Property(property: 'critical_count', type: 'integer', description: 'overall_rating < 3'),
            new OA\Property(property: 'low_dimension_count', type: 'integer', description: 'overall >= 3 with food/service/wait_time <= 2 ("atención") — not critical'),
            new OA\Property(property: 'critical', type: 'array', items: new OA\Items(ref: '#/components/schemas/DayCloseFeedbackItem')),
            new OA\Property(property: 'attention', type: 'array', description: 'Up to 20.', items: new OA\Items(ref: '#/components/schemas/DayCloseFeedbackItem')),
        ], type: 'object'),
        new OA\Property(property: 'delays', description: 'Stage duration strictly above its threshold, attributed by the stage end instant. The associated user is the one who performed the closing action of the stage — never "the one responsible".', properties: [
            new OA\Property(property: 'thresholds_seconds', properties: [
                new OA\Property(property: 'accept', type: 'integer'), new OA\Property(property: 'preparation', type: 'integer'), new OA\Property(property: 'ready_pickup', type: 'integer'),
            ], type: 'object'),
            new OA\Property(property: 'total_count', type: 'integer'),
            new OA\Property(property: 'items', type: 'array', description: 'The 20 worst by excess.', items: new OA\Items(properties: [
                new OA\Property(property: 'order_id', type: 'integer'),
                new OA\Property(property: 'order_reference', type: 'string', example: '#1234'),
                new OA\Property(property: 'table', type: 'object', nullable: true),
                new OA\Property(property: 'stage', type: 'string', enum: ['accept', 'preparation', 'ready_pickup']),
                new OA\Property(property: 'duration_seconds', type: 'integer'),
                new OA\Property(property: 'threshold_seconds', type: 'integer'),
                new OA\Property(property: 'excess_seconds', type: 'integer'),
                new OA\Property(property: 'associated_action_user', type: 'object', nullable: true),
                new OA\Property(property: 'associated_action_label', type: 'string', enum: ['marked_ready_by', 'served_by'], nullable: true),
            ], type: 'object')),
        ], type: 'object'),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'DayCloseFeedbackItem',
    properties: [
        new OA\Property(property: 'feedback_id', type: 'integer'),
        new OA\Property(property: 'submitted_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'table', type: 'object', nullable: true),
        new OA\Property(property: 'table_session_id', type: 'integer'),
        new OA\Property(property: 'ratings', properties: [
            new OA\Property(property: 'overall', type: 'integer'), new OA\Property(property: 'food', type: 'integer'),
            new OA\Property(property: 'service', type: 'integer'), new OA\Property(property: 'wait_time', type: 'integer'),
        ], type: 'object'),
        new OA\Property(property: 'experience_comment', type: 'string', nullable: true, description: 'User-generated; may itself contain PII.'),
        new OA\Property(property: 'improvement_comment', type: 'string', nullable: true),
        new OA\Property(property: 'waiter_name', type: 'string', nullable: true),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'DayCloseCash',
    properties: [
        new OA\Property(property: 'opening_float', type: 'string'),
        new OA\Property(property: 'opening_float_source', type: 'string', enum: ['previous_close', 'restaurant_default', 'required']),
        new OA\Property(property: 'cash_received', type: 'string'),
        new OA\Property(property: 'cash_pay_ins', type: 'string'),
        new OA\Property(property: 'cash_pay_outs', type: 'string'),
        new OA\Property(property: 'expected_cash', type: 'string', description: 'opening_float + cash_received + cash_pay_ins - cash_pay_outs'),
        new OA\Property(property: 'counted_cash', type: 'string'),
        new OA\Property(property: 'cash_difference', type: 'string', description: 'counted - expected (positive = sobrante, negative = faltante), computed by the server'),
        new OA\Property(property: 'cash_difference_note', type: 'string', nullable: true),
        new OA\Property(property: 'cash_difference_note_threshold', type: 'string'),
        new OA\Property(property: 'cash_left_for_next_day', type: 'string', nullable: true),
        new OA\Property(property: 'movements', type: 'array', items: new OA\Items(type: 'object')),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'DayCloseReport',
    description: 'The immutable snapshot (schema_version 1). report_sha256 = sha256 of its canonical JSON (keys sorted recursively, lists in order, unescaped unicode/slashes). Contains only strings/ints/bools/nulls.',
    allOf: [new OA\Schema(ref: '#/components/schemas/DayCloseSections')],
    properties: [
        new OA\Property(property: 'schema_version', type: 'integer', example: 1),
        new OA\Property(property: 'summary', type: 'object', description: 'Period fields + restaurant {id,name}, currency, total_received, orders_valid, guests, average_ticket, cash_difference, top_product, critical_feedback_count, delays_count, unavailable_products_count, has_incidents.'),
        new OA\Property(property: 'cash', ref: '#/components/schemas/DayCloseCash'),
        new OA\Property(property: 'warnings', type: 'array', items: new OA\Items(properties: [new OA\Property(property: 'type', type: 'string', enum: ['open_table_requests_without_active_session', 'active_staff_shifts', 'products_still_unavailable', 'critical_feedback', 'severe_delays', 'cash_difference'])], type: 'object')),
        new OA\Property(property: 'closing', properties: [
            new OA\Property(property: 'public_id', type: 'string', format: 'uuid'),
            new OA\Property(property: 'closed_by', type: 'object'),
            new OA\Property(property: 'closed_at', type: 'string', format: 'date-time'),
            new OA\Property(property: 'notes', type: 'string', nullable: true),
        ], type: 'object'),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'DayClosePreview',
    allOf: [new OA\Schema(ref: '#/components/schemas/DayCloseSections')],
    properties: [
        new OA\Property(property: 'period', ref: '#/components/schemas/DayClosePeriod'),
        new OA\Property(property: 'can_close', type: 'boolean'),
        new OA\Property(property: 'blockers', type: 'array', items: new OA\Items(properties: [
            new OA\Property(property: 'type', type: 'string', enum: ['active_session', 'business_date_already_closed', 'expected_cash_negative']),
            new OA\Property(property: 'table_session_id', type: 'integer'),
            new OA\Property(property: 'table', type: 'object'),
            new OA\Property(property: 'opened_at', type: 'string', format: 'date-time'),
            new OA\Property(property: 'payment_status', type: 'string'),
            new OA\Property(property: 'balance', type: 'string'),
            new OA\Property(property: 'open_orders_count', type: 'integer'),
            new OA\Property(property: 'can_be_voided', type: 'boolean', description: 'Empty session: POST /table-sessions/{id}/void resolves it.'),
        ], type: 'object')),
        new OA\Property(property: 'warnings', type: 'array', items: new OA\Items(type: 'object')),
        new OA\Property(property: 'cash', properties: [
            new OA\Property(property: 'suggested_opening_float', type: 'string', nullable: true),
            new OA\Property(property: 'opening_float_source', type: 'string', enum: ['previous_close', 'restaurant_default', 'required']),
            new OA\Property(property: 'cash_received', type: 'string'),
            new OA\Property(property: 'cash_pay_ins', type: 'string'),
            new OA\Property(property: 'cash_pay_outs', type: 'string'),
            new OA\Property(property: 'expected_cash', type: 'string', nullable: true, description: 'null while the opening float is required and not given via ?opening_float='),
            new OA\Property(property: 'expected_cash_excluding_opening_float', type: 'string'),
            new OA\Property(property: 'cash_difference_note_threshold', type: 'string'),
            new OA\Property(property: 'movements', type: 'array', items: new OA\Items(type: 'object')),
        ], type: 'object'),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'DayCloseSummary',
    description: 'History row — persisted columns, never recomputed.',
    properties: [
        new OA\Property(property: 'id', type: 'integer', format: 'int64'),
        new OA\Property(property: 'public_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'restaurant_id', type: 'integer', format: 'int64'),
        new OA\Property(property: 'business_date', type: 'string', format: 'date'),
        new OA\Property(property: 'business_date_from', type: 'string', format: 'date'),
        new OA\Property(property: 'period_started_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'period_ended_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'timezone', type: 'string'),
        new OA\Property(property: 'currency', type: 'string'),
        new OA\Property(property: 'total_received', type: 'string'),
        new OA\Property(property: 'orders_valid', type: 'integer'),
        new OA\Property(property: 'guests', type: 'integer'),
        new OA\Property(property: 'cash_difference', type: 'string'),
        new OA\Property(property: 'critical_feedback_count', type: 'integer'),
        new OA\Property(property: 'delays_count', type: 'integer'),
        new OA\Property(property: 'unavailable_products_count', type: 'integer'),
        new OA\Property(property: 'has_incidents', type: 'boolean', description: 'cash difference != 0, critical feedback, severe delay, product marked unavailable, or closing notes'),
        new OA\Property(property: 'closed_by', type: 'object'),
        new OA\Property(property: 'closed_at', type: 'string', format: 'date-time'),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'DayClose',
    description: 'A persisted Cierre Diario: DayCloseSummary fields plus every persisted column, the immutable report and its hash, and annotations (separate).',
    allOf: [new OA\Schema(ref: '#/components/schemas/DayCloseSummary')],
    properties: [
        new OA\Property(property: 'cash_received', type: 'string'),
        new OA\Property(property: 'card_received', type: 'string'),
        new OA\Property(property: 'other_received', type: 'string'),
        new OA\Property(property: 'payments_count', type: 'integer'),
        new OA\Property(property: 'sessions_with_payments', type: 'integer'),
        new OA\Property(property: 'average_ticket', type: 'string'),
        new OA\Property(property: 'opening_float', type: 'string'),
        new OA\Property(property: 'cash_pay_ins', type: 'string'),
        new OA\Property(property: 'cash_pay_outs', type: 'string'),
        new OA\Property(property: 'expected_cash', type: 'string'),
        new OA\Property(property: 'counted_cash', type: 'string'),
        new OA\Property(property: 'cash_left_for_next_day', type: 'string', nullable: true),
        new OA\Property(property: 'cash_difference_note', type: 'string', nullable: true),
        new OA\Property(property: 'orders_registered', type: 'integer'),
        new OA\Property(property: 'orders_served', type: 'integer'),
        new OA\Property(property: 'orders_rejected', type: 'integer'),
        new OA\Property(property: 'sessions_opened', type: 'integer'),
        new OA\Property(property: 'sessions_closed', type: 'integer'),
        new OA\Property(property: 'feedback_count', type: 'integer'),
        new OA\Property(property: 'feedback_avg_overall', type: 'string', nullable: true),
        new OA\Property(property: 'low_dimension_feedback_count', type: 'integer'),
        new OA\Property(property: 'notes', type: 'string', nullable: true),
        new OA\Property(property: 'report_schema_version', type: 'integer', example: 1),
        new OA\Property(property: 'report_sha256', type: 'string'),
        new OA\Property(property: 'report', ref: '#/components/schemas/DayCloseReport'),
        new OA\Property(property: 'annotations', type: 'array', items: new OA\Items(ref: '#/components/schemas/DayCloseAnnotation')),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
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
        new OA\Property(property: 'google_review_url', type: 'string', format: 'uri', maxLength: 2048, example: 'https://g.page/r/CabcdEFGhij123/review', nullable: true, description: 'Trimmed; an empty/blank string or null clears it. Must be an HTTPS Google review/share link in a format currently supported by AFORO (see RestaurantSettings.google_review_url) — anything else is 422. The scheme is case-insensitive (HTTPS:// is accepted and stored as https://).'),
        new OA\Property(property: 'waiter_table_management_enabled', type: 'boolean', example: false, description: 'See RestaurantSettings.waiter_table_management_enabled. Like every field here, only changeable by users authorized to manage the restaurant settings (manage_restaurants).'),
        new OA\Property(property: 'business_day_cutoff_time', type: 'string', pattern: '^([01]\\d|2[0-3]):[0-5]\\d$', example: '06:00', description: 'CARTA 9.1A — local HH:MM. A Cierre Diario whose local time is before it belongs to the previous business date.'),
        new OA\Property(property: 'default_opening_float', type: 'string', nullable: true, example: '150.00', description: 'Opening float used when the previous close left no cash_left_for_next_day (e.g. the first close). null = the closer must state it.'),
        new OA\Property(property: 'cash_difference_note_threshold', type: 'string', example: '5.00', description: 'A cash_difference_note is required when |cash difference| is strictly greater than this.'),
        new OA\Property(property: 'accept_delay_threshold_minutes', type: 'integer', minimum: 1, maximum: 240),
        new OA\Property(property: 'preparation_delay_threshold_minutes', type: 'integer', minimum: 1, maximum: 240),
        new OA\Property(property: 'ready_pickup_delay_threshold_minutes', type: 'integer', minimum: 1, maximum: 240),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'OperationsSummary',
    description: 'Operational KPIs for right now — never a period/historical metric (see RestaurantDashboard for that).',
    properties: [
        new OA\Property(
            property: 'tables',
            properties: [
                new OA\Property(property: 'total', type: 'integer', example: 12),
                new OA\Property(property: 'free', type: 'integer', example: 7),
                new OA\Property(property: 'occupied', type: 'integer', example: 5),
                new OA\Property(property: 'occupancy_rate', type: 'number', format: 'float', example: 0.4167, description: 'occupied / total, 0..1, rounded to 4 decimals. 0 when there are no tables.'),
            ],
            type: 'object'
        ),
        new OA\Property(property: 'active_sessions', type: 'integer', example: 5),
        new OA\Property(property: 'active_guests', type: 'integer', example: 17, description: 'SUM(guest_count) of active sessions only.'),
        new OA\Property(
            property: 'orders',
            properties: [
                new OA\Property(property: 'active', type: 'integer', example: 6, description: 'Every open order: waiting_approval/confirmed/accepted/preparing/ready.'),
                new OA\Property(property: 'waiting_approval', type: 'integer', example: 1),
                new OA\Property(property: 'preparing', type: 'integer', example: 3, description: 'status = preparing exactly (see kitchen.counts_by_status for confirmed/accepted too).'),
                new OA\Property(property: 'ready', type: 'integer', example: 2),
            ],
            type: 'object'
        ),
        new OA\Property(property: 'staff', properties: [new OA\Property(property: 'active', type: 'integer', example: 4)], type: 'object'),
        new OA\Property(property: 'requests', properties: [new OA\Property(property: 'pending', type: 'integer', example: 2)], type: 'object'),
        new OA\Property(property: 'sales', properties: [new OA\Property(property: 'received_today', type: 'string', example: '842.50', description: 'SUM(PaymentRecord.amount) since local midnight (RestaurantSettings.timezone), never Order totals.')], type: 'object'),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'OperationsTable',
    description: 'A Table\'s physical + derived operational state. primary_status/flags are always derived — never a persisted column (see TableOperationalStateResolver).',
    required: ['id', 'name', 'primary_status', 'flags'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 7),
        new OA\Property(property: 'name', type: 'string', example: 'Mesa 7'),
        new OA\Property(property: 'number', type: 'integer', nullable: true, example: 7),
        new OA\Property(property: 'capacity', type: 'integer', nullable: true, example: 4),
        new OA\Property(property: 'zone_id', type: 'integer', format: 'int64', nullable: true, example: 3),
        new OA\Property(
            property: 'layout',
            properties: [
                new OA\Property(property: 'x', type: 'number', format: 'float', nullable: true),
                new OA\Property(property: 'y', type: 'number', format: 'float', nullable: true),
                new OA\Property(property: 'rotation', type: 'integer', nullable: true),
                new OA\Property(property: 'shape', type: 'string', nullable: true, example: 'round'),
                new OA\Property(property: 'width', type: 'number', format: 'float', nullable: true),
                new OA\Property(property: 'height', type: 'number', format: 'float', nullable: true),
            ],
            type: 'object'
        ),
        new OA\Property(property: 'primary_status', type: 'string', example: 'ready', description: 'One of: free, occupied, waiting_approval, preparing, ready, waiter_requested, bill_requested.'),
        new OA\Property(property: 'flags', type: 'array', items: new OA\Items(type: 'string'), example: ['ready_order', 'assigned_waiter_off_shift']),
        new OA\Property(
            property: 'session',
            nullable: true,
            properties: [
                new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 381),
                new OA\Property(property: 'started_at', type: 'string', format: 'date-time'),
                new OA\Property(property: 'elapsed_seconds', type: 'integer', example: 1320),
                new OA\Property(property: 'guest_count', type: 'integer', example: 4),
                new OA\Property(
                    property: 'assigned_waiter',
                    nullable: true,
                    properties: [
                        new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 12),
                        new OA\Property(property: 'name', type: 'string', nullable: true, example: 'Mateo'),
                        new OA\Property(property: 'sub_id', type: 'string', nullable: true, example: 'W-1'),
                    ],
                    type: 'object'
                ),
            ],
            type: 'object'
        ),
        new OA\Property(
            property: 'orders',
            properties: [
                new OA\Property(property: 'open_count', type: 'integer', example: 2),
                new OA\Property(property: 'waiting_approval', type: 'integer', example: 0),
                new OA\Property(property: 'preparing', type: 'integer', example: 1),
                new OA\Property(property: 'ready', type: 'integer', example: 1),
            ],
            type: 'object'
        ),
        new OA\Property(
            property: 'billing',
            nullable: true,
            description: 'null when the session has no billable orders yet. Computed via SessionBillCalculator — never re-implemented here.',
            properties: [
                new OA\Property(property: 'total', type: 'string', example: '84.50'),
                new OA\Property(property: 'paid', type: 'string', example: '40.00'),
                new OA\Property(property: 'outstanding', type: 'string', example: '44.50'),
                new OA\Property(property: 'status', type: 'string', example: 'partial', description: 'One of: unpaid, partial, paid.'),
            ],
            type: 'object'
        ),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'OperationsStaff',
    required: ['user', 'load'],
    properties: [
        new OA\Property(property: 'user', properties: [new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 12), new OA\Property(property: 'name', type: 'string', nullable: true, example: 'Mateo')], type: 'object'),
        new OA\Property(property: 'role', type: 'string', nullable: true, example: 'waiter'),
        new OA\Property(property: 'shift_started_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'active_seconds', type: 'integer', example: 5400),
        new OA\Property(
            property: 'load',
            properties: [
                new OA\Property(property: 'assigned_tables', type: 'integer', example: 2),
                new OA\Property(property: 'assigned_guests', type: 'integer', example: 7),
                new OA\Property(property: 'pending_attention', type: 'integer', example: 1, description: 'Open TableRequests + pending WaiterCalls across this waiter\'s assigned active sessions.'),
            ],
            type: 'object'
        ),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'OperationsKitchen',
    properties: [
        new OA\Property(property: 'counts_by_status', properties: [
            new OA\Property(property: 'waiting_approval', type: 'integer', example: 1),
            new OA\Property(property: 'confirmed', type: 'integer', example: 2),
            new OA\Property(property: 'accepted', type: 'integer', example: 1),
            new OA\Property(property: 'preparing', type: 'integer', example: 2),
            new OA\Property(property: 'ready', type: 'integer', example: 1),
        ], type: 'object'),
        new OA\Property(property: 'active_orders', type: 'integer', example: 7),
        new OA\Property(property: 'oldest_active_order_age_seconds', type: 'integer', nullable: true, example: 640),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'OperationsAlert',
    required: ['id', 'type', 'severity', 'age_seconds'],
    properties: [
        new OA\Property(property: 'id', type: 'string', example: 'waiter-off-shift-381', description: 'Deterministic, stable across snapshots of the same underlying fact.'),
        new OA\Property(property: 'type', type: 'string', example: 'assigned_waiter_off_shift', description: 'One of: active_table_unassigned, assigned_waiter_off_shift, assigned_waiter_suspended, customer_waiter_request_pending, bill_request_pending, responsible_waiter_call_pending, order_waiting_approval, order_ready.'),
        new OA\Property(property: 'severity', type: 'string', example: 'warning', description: 'One of: info, warning, critical.'),
        new OA\Property(property: 'table_id', type: 'integer', format: 'int64', nullable: true, example: 7),
        new OA\Property(property: 'table_session_id', type: 'integer', format: 'int64', nullable: true, example: 381),
        new OA\Property(property: 'user_id', type: 'integer', format: 'int64', nullable: true, example: 12),
        new OA\Property(property: 'age_seconds', type: 'integer', example: 240),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'OperationsBottleneck',
    nullable: true,
    description: 'null when there are no alerts. Deterministic: highest severity, then highest affected_count, then oldest — see OperationsBottleneckResolver.',
    properties: [
        new OA\Property(property: 'type', type: 'string', example: 'bill_request_pending'),
        new OA\Property(property: 'severity', type: 'string', example: 'warning'),
        new OA\Property(property: 'affected_count', type: 'integer', example: 3),
        new OA\Property(property: 'oldest_age_seconds', type: 'integer', example: 420),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'OperationsLiveSnapshot',
    description: 'The full Operations Live read-model response body (Bloco 5) — everything derived at request time, nothing persisted, nothing cached.',
    required: ['restaurant', 'generated_at', 'summary', 'operation', 'floors', 'unassigned_tables', 'staff', 'kitchen', 'alerts'],
    properties: [
        new OA\Property(property: 'restaurant', properties: [
            new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 2),
            new OA\Property(property: 'name', type: 'string', example: 'Aforo Centro'),
            new OA\Property(property: 'timezone', type: 'string', example: 'Europe/Madrid'),
            new OA\Property(property: 'currency', type: 'string', example: 'EUR'),
        ], type: 'object'),
        new OA\Property(property: 'generated_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'summary', ref: '#/components/schemas/OperationsSummary'),
        new OA\Property(property: 'operation', properties: [
            new OA\Property(property: 'health_score', type: 'integer', example: 84),
            new OA\Property(property: 'health_level', type: 'string', example: 'healthy', description: 'One of: healthy (80-100), attention (50-79), critical (0-49).'),
            new OA\Property(property: 'bottleneck', ref: '#/components/schemas/OperationsBottleneck'),
        ], type: 'object'),
        new OA\Property(
            property: 'floors',
            type: 'array',
            items: new OA\Items(properties: [
                new OA\Property(property: 'id', type: 'integer', format: 'int64'),
                new OA\Property(property: 'name', type: 'string'),
                new OA\Property(property: 'sort_order', type: 'integer'),
                new OA\Property(property: 'is_active', type: 'boolean'),
                new OA\Property(property: 'zones', type: 'array', items: new OA\Items(properties: [
                    new OA\Property(property: 'id', type: 'integer', format: 'int64'),
                    new OA\Property(property: 'name', type: 'string'),
                    new OA\Property(property: 'sort_order', type: 'integer'),
                    new OA\Property(property: 'is_active', type: 'boolean'),
                    new OA\Property(property: 'tables', type: 'array', items: new OA\Items(ref: '#/components/schemas/OperationsTable')),
                ], type: 'object')),
            ], type: 'object')
        ),
        new OA\Property(property: 'unassigned_tables', type: 'array', items: new OA\Items(ref: '#/components/schemas/OperationsTable')),
        new OA\Property(property: 'staff', type: 'array', items: new OA\Items(ref: '#/components/schemas/OperationsStaff')),
        new OA\Property(property: 'kitchen', ref: '#/components/schemas/OperationsKitchen'),
        new OA\Property(property: 'alerts', type: 'array', items: new OA\Items(ref: '#/components/schemas/OperationsAlert')),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'AnalyticsPeriod',
    properties: [
        new OA\Property(property: 'from', type: 'string', format: 'date', example: '2026-09-01'),
        new OA\Property(property: 'to', type: 'string', format: 'date', example: '2026-09-30'),
        new OA\Property(property: 'granularity', type: 'string', example: 'day', description: 'One of: day, week, month.'),
        new OA\Property(property: 'timezone', type: 'string', example: 'Europe/Madrid'),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'AnalyticsSummary',
    properties: [
        new OA\Property(property: 'revenue', type: 'string', example: '1284.50', description: 'Sum of PaymentRecord.amount received in the period — never derived from Order totals.'),
        new OA\Property(property: 'average_ticket', type: 'string', example: '42.82', description: 'revenue / sessions_with_payments, same semantics as the existing /dashboard endpoint.'),
        new OA\Property(property: 'sessions_with_payments', type: 'integer', example: 30),
        new OA\Property(property: 'orders_count', type: 'integer', example: 54, description: 'Every Order created in the period, any status.'),
        new OA\Property(property: 'guests_served', type: 'integer', example: 112, description: 'SUM(guest_count) of sessions closed within the period.'),
        new OA\Property(property: 'closed_sessions', type: 'integer', example: 30),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'AnalyticsSeriesPoint',
    properties: [
        new OA\Property(property: 'period_start', type: 'string', example: '2026-09-01', description: 'Local bucket key — a day, or the Monday of a week, or the first of a month, per the requested granularity. Always zero-filled, never sparse.'),
        new OA\Property(property: 'revenue', type: 'string', example: '210.00'),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'AnalyticsTableTurnover',
    properties: [
        new OA\Property(property: 'closed_sessions', type: 'integer', example: 30),
        new OA\Property(property: 'turnover_per_table', type: 'number', format: 'float', example: 2.5, description: 'closed_sessions / total_tables. 0 when there are no tables.'),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'AnalyticsOccupancy',
    properties: [
        new OA\Property(property: 'occupancy_rate', type: 'number', format: 'float', example: 0.42, description: 'occupied_seconds / (total_tables * period_seconds), via effective_start/effective_end overlap clamping. 0 when there are no tables.'),
        new OA\Property(property: 'occupied_seconds', type: 'integer', example: 145800),
        new OA\Property(property: 'total_tables', type: 'integer', example: 12, description: 'The restaurant\'s CURRENT table count — an approximation for a period in which tables were added/removed (see the Bloco 6 report).'),
        new OA\Property(property: 'average_session_duration_seconds', type: 'integer', nullable: true, example: 3120, description: 'Only over sessions actually closed in the period. null when none closed.'),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'AnalyticsPeakHour',
    properties: [
        new OA\Property(property: 'hour', type: 'integer', example: 21, description: 'Local hour of day, 0-23. Always all 24 present, zero-filled.'),
        new OA\Property(property: 'sessions_started', type: 'integer', example: 8),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'AnalyticsProduct',
    properties: [
        new OA\Property(property: 'id', type: 'integer', format: 'int64', nullable: true, example: 14, description: 'null when the live Product/RestaurantProduct row is gone — the row still exists via the OrderItem snapshot.'),
        new OA\Property(property: 'name', type: 'string', example: 'Paella Valenciana', description: 'The snapshot name as sold, never the live (possibly renamed) Product name.'),
        new OA\Property(property: 'quantity', type: 'integer', example: 37),
        new OA\Property(property: 'revenue', type: 'string', example: '666.00'),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'AnalyticsKitchen',
    properties: [
        new OA\Property(property: 'orders_created', type: 'integer', example: 54),
        new OA\Property(property: 'orders_ready', type: 'integer', example: 48),
        new OA\Property(property: 'orders_cancelled', type: 'integer', example: 3),
        new OA\Property(property: 'average_preparation_time_seconds', type: 'integer', nullable: true, example: 540, description: 'AVG(ready_at - preparing_at), only over orders that actually reached ready. null (never 0 or fabricated) when none did.'),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'AnalyticsOrders',
    properties: [
        new OA\Property(property: 'total', type: 'integer', example: 54),
        new OA\Property(property: 'by_origin', properties: [
            new OA\Property(property: 'customer_qr', type: 'integer', example: 20),
            new OA\Property(property: 'waiter', type: 'integer', example: 28),
            new OA\Property(property: 'manager', type: 'integer', example: 4),
            new OA\Property(property: 'cashier', type: 'integer', example: 2),
        ], type: 'object'),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'AnalyticsStaff',
    description: 'Roster defined by StaffShift presence overlapping the period — not by order activity. Deliberately never carries sales_attributed or guests_served: TableSession.assigned_waiter_user_id is the final/current responsible waiter, not a reassignment-aware history, so per-waiter revenue/guest attribution would be unreliable (see the Bloco 6 report).',
    properties: [
        new OA\Property(property: 'user', properties: [
            new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 12),
            new OA\Property(property: 'name', type: 'string', nullable: true, example: 'Mateo'),
        ], type: 'object'),
        new OA\Property(property: 'role', type: 'string', nullable: true, example: 'waiter'),
        new OA\Property(property: 'shift_count', type: 'integer', example: 4),
        new OA\Property(property: 'active_seconds', type: 'integer', example: 28800),
        new OA\Property(property: 'tables_served', type: 'integer', example: 22),
        new OA\Property(property: 'orders_created', type: 'integer', example: 30),
        new OA\Property(property: 'orders_served', type: 'integer', example: 22),
        new OA\Property(property: 'customer_orders_approved', type: 'integer', example: 5),
        new OA\Property(property: 'table_requests_handled', type: 'integer', example: 9),
        new OA\Property(property: 'sessions_closed', type: 'integer', example: 18),
        new OA\Property(property: 'average_rating', type: 'string', nullable: true, example: '4.50'),
        new OA\Property(property: 'reviews_count', type: 'integer', example: 6),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'RestaurantAnalytics',
    description: 'The full Analytics read-model response body (Bloco 6) — a historical/descriptive read model for an explicit period, distinct from Operations Live (current state) and computed fresh on every request: nothing persisted, nothing cached.',
    required: ['restaurant', 'period', 'summary', 'revenue_series', 'occupancy', 'table_turnover', 'peak_hours', 'products', 'kitchen', 'orders', 'staff'],
    properties: [
        new OA\Property(property: 'restaurant', properties: [
            new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 2),
            new OA\Property(property: 'name', type: 'string', example: 'Aforo Centro'),
            new OA\Property(property: 'timezone', type: 'string', example: 'Europe/Madrid'),
            new OA\Property(property: 'currency', type: 'string', example: 'EUR'),
        ], type: 'object'),
        new OA\Property(property: 'period', ref: '#/components/schemas/AnalyticsPeriod'),
        new OA\Property(property: 'summary', ref: '#/components/schemas/AnalyticsSummary'),
        new OA\Property(property: 'revenue_series', type: 'array', items: new OA\Items(ref: '#/components/schemas/AnalyticsSeriesPoint')),
        new OA\Property(property: 'occupancy', ref: '#/components/schemas/AnalyticsOccupancy'),
        new OA\Property(property: 'table_turnover', ref: '#/components/schemas/AnalyticsTableTurnover'),
        new OA\Property(property: 'peak_hours', type: 'array', items: new OA\Items(ref: '#/components/schemas/AnalyticsPeakHour')),
        new OA\Property(property: 'products', properties: [
            new OA\Property(property: 'top_by_quantity', type: 'array', items: new OA\Items(ref: '#/components/schemas/AnalyticsProduct')),
            new OA\Property(property: 'top_by_revenue', type: 'array', items: new OA\Items(ref: '#/components/schemas/AnalyticsProduct')),
        ], type: 'object'),
        new OA\Property(property: 'kitchen', ref: '#/components/schemas/AnalyticsKitchen'),
        new OA\Property(property: 'orders', ref: '#/components/schemas/AnalyticsOrders'),
        new OA\Property(property: 'staff', type: 'array', items: new OA\Items(ref: '#/components/schemas/AnalyticsStaff')),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'RestaurantActivityType',
    description: 'Operational activity type (CARTA 6.1A). Only transitions that exist in the domain: order.rejected is the sole way an order becomes cancelled today, so there is no generic order.cancelled; product.* is about the on/off availability flag (RestaurantProduct.available), not stock. waiter_request.* / bill_request.* are the customer QR requests (TableRequest call_waiter / request_bill), not the internal WaiterCall escalation.',
    type: 'string',
    enum: [
        'order.created', 'order.approved', 'order.rejected', 'order.accepted', 'order.preparing', 'order.ready', 'order.served',
        'waiter_request.created', 'waiter_request.acknowledged', 'waiter_request.completed',
        'bill_request.created', 'bill_request.acknowledged', 'bill_request.completed',
        'payment.recorded',
        'table_session.opened', 'table_session.closed',
        'product.marked_unavailable', 'product.marked_available',
    ],
    example: 'order.ready'
)]
#[OA\Schema(
    schema: 'RestaurantActivityTone',
    description: 'Semantic presentation tone for the operational event. It does not indicate whether current action is required (that is GET /operations/live alerts) — e.g. order.ready is positive (progress completed) even while an alert may say the order is waiting to be picked up. The backend never returns colors; the client maps each tone to its own color/icon/surface/theme. Derived from the type: neutral → table_session.opened, order.created, order.approved, order.accepted, order.preparing, waiter_request.acknowledged, bill_request.acknowledged; positive → order.ready, order.served, payment.recorded, table_session.closed, waiter_request.completed, bill_request.completed, product.marked_available; warning → waiter_request.created, bill_request.created, product.marked_unavailable; critical → order.rejected.',
    type: 'string',
    enum: ['neutral', 'positive', 'warning', 'critical'],
    example: 'positive'
)]
#[OA\Schema(
    schema: 'RestaurantActivityEvent',
    description: 'One immutable entry of a restaurant\'s operational activity feed — something that already happened. Rendered from snapshots taken when it happened (actor/table names, order reference), so later renames never rewrite history. This exact shape is also the `activity` payload of the `restaurant.activity.created` realtime event. It is history only: whether something requires action is answered by GET /operations/live alerts, never by this feed.',
    required: ['id', 'type', 'category', 'tone', 'occurred_at', 'actor', 'table', 'table_session_id', 'order', 'table_request_id', 'metadata'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 5120, description: 'Monotonic within the feed; the feed is ordered by it (newest first) and read cursors compare against it.'),
        new OA\Property(property: 'type', ref: '#/components/schemas/RestaurantActivityType'),
        new OA\Property(property: 'category', type: 'string', enum: ['orders', 'service', 'billing', 'tables', 'menu'], example: 'orders', description: 'UI grouping only — orders: order.*; service: waiter_request.*; billing: bill_request.*, payment.recorded; tables: table_session.*; menu: product.*.'),
        new OA\Property(property: 'tone', ref: '#/components/schemas/RestaurantActivityTone'),
        new OA\Property(property: 'occurred_at', type: 'string', format: 'date-time', description: 'When the domain transition happened (the domain\'s own timestamp — e.g. the order\'s ready_at — for order/request/payment/session events). ISO 8601 UTC.'),
        new OA\Property(
            property: 'actor',
            required: ['type', 'id', 'name'],
            properties: [
                new OA\Property(property: 'type', type: 'string', enum: ['staff', 'customer'], example: 'staff', description: 'customer = anonymous QR guest: id and name are always null (no customer identity is ever stored or invented).'),
                new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 14, nullable: true),
                new OA\Property(property: 'name', type: 'string', example: 'Carlos García', nullable: true, description: 'Snapshot of the staff member\'s name at that moment.'),
            ],
            type: 'object'
        ),
        new OA\Property(
            property: 'table',
            required: ['id', 'name'],
            properties: [
                new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 12),
                new OA\Property(property: 'name', type: 'string', example: 'Mesa 12', description: 'Snapshot of the table name at that moment.'),
            ],
            type: 'object',
            nullable: true
        ),
        new OA\Property(property: 'table_session_id', type: 'integer', format: 'int64', example: 311, nullable: true),
        new OA\Property(
            property: 'order',
            required: ['id', 'reference'],
            properties: [
                new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 1842),
                new OA\Property(property: 'reference', type: 'string', example: '#1842', description: 'Same value as Order.order_number.'),
            ],
            type: 'object',
            nullable: true
        ),
        new OA\Property(property: 'table_request_id', type: 'integer', format: 'int64', example: 77, nullable: true, description: 'Set for waiter_request.* / bill_request.*.'),
        new OA\Property(
            property: 'metadata',
            description: 'Fixed keys per type (null for every type not listed): order.created → origin (customer_qr|waiter), initial_status (waiting_approval|confirmed), item_count (sum of line quantities), total (decimal string); payment.recorded → payment_id, amount (decimal string), method (cash|card|other); table_session.opened → guest_count; table_session.closed → total (billable orders total, decimal string); product.marked_unavailable / product.marked_available → restaurant_product_id, product_name (the product\'s internal name). Never contains IPs, user agents, tokens, contact details, customer notes or payment credentials.',
            type: 'object',
            nullable: true,
            example: ['origin' => 'customer_qr', 'initial_status' => 'waiting_approval', 'item_count' => 3, 'total' => '23.50']
        ),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'RestaurantActivityReadState',
    required: ['last_read_event_id', 'unread_count'],
    properties: [
        new OA\Property(property: 'last_read_event_id', type: 'integer', format: 'int64', example: 5100, description: 'The requesting user\'s own cursor on this restaurant (0 = never marked).'),
        new OA\Property(property: 'unread_count', type: 'integer', example: 3, description: 'Events of this restaurant with id > last_read_event_id.'),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'RestaurantActivityMeta',
    required: ['per_page', 'next_cursor', 'prev_cursor', 'last_read_event_id', 'unread_count'],
    properties: [
        new OA\Property(property: 'per_page', type: 'integer', example: 25),
        new OA\Property(property: 'next_cursor', type: 'string', nullable: true, description: 'Pass as ?cursor= to load older events; null on the last page.'),
        new OA\Property(property: 'prev_cursor', type: 'string', nullable: true, description: 'Pass as ?cursor= to go back towards newer events; null on the first page.'),
        new OA\Property(property: 'last_read_event_id', type: 'integer', format: 'int64', example: 5100),
        new OA\Property(property: 'unread_count', type: 'integer', example: 3, description: 'Unaffected by the category/type/period filters — always the whole restaurant feed.'),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'KitchenDashboardRecentOrder',
    description: 'A short kitchen-facing order line — never prices, payments or customer data. recent_accepted items carry accepted_at/accepted_by; recent_ready items carry ready_at/ready_by plus served_at (null while still waiting for a waiter).',
    required: ['order', 'table', 'status', 'items'],
    properties: [
        new OA\Property(property: 'order', required: ['id', 'reference'], properties: [
            new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 1842),
            new OA\Property(property: 'reference', type: 'string', example: '#1842'),
        ], type: 'object'),
        new OA\Property(property: 'table', required: ['id', 'name'], properties: [
            new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 7),
            new OA\Property(property: 'name', type: 'string', example: 'Mesa 07'),
        ], type: 'object'),
        new OA\Property(property: 'status', type: 'string', example: 'ready', description: 'The order\'s CURRENT status.'),
        new OA\Property(property: 'accepted_at', type: 'string', format: 'date-time', description: 'recent_accepted only.'),
        new OA\Property(property: 'accepted_by', properties: [
            new OA\Property(property: 'id', type: 'integer', format: 'int64'),
            new OA\Property(property: 'name', type: 'string', example: 'Pablo Torres'),
        ], type: 'object', nullable: true, description: 'recent_accepted only. The user who performed that transition.'),
        new OA\Property(property: 'ready_at', type: 'string', format: 'date-time', description: 'recent_ready only.'),
        new OA\Property(property: 'ready_by', properties: [
            new OA\Property(property: 'id', type: 'integer', format: 'int64'),
            new OA\Property(property: 'name', type: 'string'),
        ], type: 'object', nullable: true, description: 'recent_ready only.'),
        new OA\Property(property: 'served_at', type: 'string', format: 'date-time', nullable: true, description: 'recent_ready only — null while the ready order still waits for a waiter.'),
        new OA\Property(property: 'items', type: 'array', items: new OA\Items(required: ['name', 'quantity'], properties: [
            new OA\Property(property: 'name', type: 'string', example: 'Croquetas caseras', description: 'Snapshot taken when ordered.'),
            new OA\Property(property: 'quantity', type: 'integer', example: 2),
        ], type: 'object')),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'KitchenDashboardTiming',
    required: ['average_seconds', 'orders'],
    properties: [
        new OA\Property(property: 'average_seconds', type: 'integer', nullable: true, example: 720, description: 'Null when no order completed this transition in the period (never 0).'),
        new OA\Property(property: 'orders', type: 'integer', example: 14, description: 'Sample size: orders whose END timestamp falls in the period.'),
    ],
    type: 'object'
)]
#[OA\Schema(
    schema: 'KitchenDashboard',
    description: 'Kitchen Dashboard read model (CARTA 7.1A). `queue` is live (now); everything else covers `period` = today in the restaurant\'s timezone, up to now. Kitchen staff cannot read /operations/live or /analytics, hence the own queue summary — restricted to kitchen statuses (confirmed/accepted/preparing/ready, same as GET /kitchen/orders; waiting_approval is not kitchen work). All durations use the lifecycle\'s exact timestamps, never updated_at.',
    required: ['restaurant', 'generated_at', 'period', 'queue', 'timings', 'top_products', 'recent_accepted', 'recent_ready'],
    properties: [
        new OA\Property(property: 'restaurant', required: ['id', 'name', 'timezone'], properties: [
            new OA\Property(property: 'id', type: 'integer', format: 'int64', example: 1),
            new OA\Property(property: 'name', type: 'string', example: 'AFORO Malvarrosa'),
            new OA\Property(property: 'timezone', type: 'string', example: 'Europe/Madrid'),
        ], type: 'object'),
        new OA\Property(property: 'generated_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'period', required: ['from', 'to'], properties: [
            new OA\Property(property: 'from', type: 'string', format: 'date-time', description: 'Start of today, restaurant local time, as UTC.'),
            new OA\Property(property: 'to', type: 'string', format: 'date-time', description: 'Now.'),
        ], type: 'object'),
        new OA\Property(property: 'queue', required: ['counts_by_status', 'active_orders', 'oldest_active_order_age_seconds', 'longest_ready_wait_seconds'], properties: [
            new OA\Property(property: 'counts_by_status', required: ['confirmed', 'accepted', 'preparing', 'ready'], properties: [
                new OA\Property(property: 'confirmed', type: 'integer', example: 3, description: 'New for the kitchen ("Pedidos nuevos").'),
                new OA\Property(property: 'accepted', type: 'integer', example: 4),
                new OA\Property(property: 'preparing', type: 'integer', example: 6),
                new OA\Property(property: 'ready', type: 'integer', example: 2),
            ], type: 'object'),
            new OA\Property(property: 'active_orders', type: 'integer', example: 15),
            new OA\Property(property: 'oldest_active_order_age_seconds', type: 'integer', nullable: true, example: 840, description: 'order_age: now − created_at of the oldest order in a kitchen status. Null when the queue is empty.'),
            new OA\Property(property: 'longest_ready_wait_seconds', type: 'integer', nullable: true, example: 190, description: 'ready_wait (live): now − ready_at of the oldest order still ready. Null when nothing is ready.'),
        ], type: 'object'),
        new OA\Property(property: 'timings', required: ['accept', 'preparation', 'ready_to_served'], properties: [
            new OA\Property(property: 'accept', ref: '#/components/schemas/KitchenDashboardTiming', description: 'accepted_at − COALESCE(approved_at, created_at): confirmed → taken by the kitchen (excludes a customer order\'s approval wait), over orders accepted in the period.'),
            new OA\Property(property: 'preparation', ref: '#/components/schemas/KitchenDashboardTiming', description: 'preparation_time: ready_at − preparing_at, over orders ready in the period (same definition as Analytics average_preparation_time_seconds).'),
            new OA\Property(property: 'ready_to_served', ref: '#/components/schemas/KitchenDashboardTiming', description: 'ready_wait_time: served_at − ready_at — how long a ready order waited for a waiter — over orders served in the period.'),
        ], type: 'object'),
        new OA\Property(property: 'top_products', type: 'array', description: 'Up to 5, by quantity — same rule as Analytics top_by_quantity (item snapshots, billable statuses, Order.created_at in the period); no revenue here.', items: new OA\Items(required: ['product_id', 'name', 'quantity'], properties: [
            new OA\Property(property: 'product_id', type: 'integer', format: 'int64', nullable: true, example: 12),
            new OA\Property(property: 'name', type: 'string', example: 'Croquetas caseras'),
            new OA\Property(property: 'quantity', type: 'integer', example: 23),
        ], type: 'object')),
        new OA\Property(property: 'recent_accepted', type: 'array', description: 'Up to 5 orders accepted today, newest first.', items: new OA\Items(ref: '#/components/schemas/KitchenDashboardRecentOrder')),
        new OA\Property(property: 'recent_ready', type: 'array', description: 'Up to 5 orders that became ready today, newest first.', items: new OA\Items(ref: '#/components/schemas/KitchenDashboardRecentOrder')),
    ],
    type: 'object'
)]
class ApiDocumentation {}
