<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\AuditLog\IndexAuditLogRequest;
use App\Http\Resources\Api\V1\AuditLogResource;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\Restaurant;
use App\Support\Audit\AuditLogPeriodResolver;
use App\Support\Restaurants\RestaurantScope;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

class AuditLogController extends Controller
{
    private const DEFAULT_PER_PAGE = 25;

    public function __construct(private readonly TenantContext $tenantContext) {}

    /**
     * List domain audit events visible to the active organization, scoped
     * to the restaurants the acting user may operate on (RestaurantScope)
     * — an organization-wide requester (owner) sees every restaurant, an
     * operational requester (manager) only their own. Never another
     * organization's events, under any filter combination.
     */
    #[OA\Get(
        path: '/api/v1/audit-logs',
        operationId: 'auditLogsIndex',
        summary: 'List domain audit events',
        security: [['sessionCookie' => []]],
        tags: ['Audit Log'],
        parameters: [
            new OA\Parameter(name: 'restaurant_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'actor_user_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'event', in: 'query', required: false, schema: new OA\Schema(type: 'string', example: 'order.served')),
            new OA\Parameter(name: 'resource_type', in: 'query', required: false, schema: new OA\Schema(type: 'string', example: 'order')),
            new OA\Parameter(name: 'resource_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 25)),
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 1)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'A page of audit events, newest first',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'data',
                            properties: [
                                new OA\Property(
                                    property: 'audit_logs',
                                    type: 'array',
                                    items: new OA\Items(ref: '#/components/schemas/AuditLog')
                                ),
                            ],
                            type: 'object'
                        ),
                        new OA\Property(property: 'meta', ref: '#/components/schemas/AuditLogPaginationMeta'),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'The user is not allowed to view the audit log'),
            new OA\Response(response: 404, description: 'restaurant_id is outside the active organization or the requester\'s RestaurantScope'),
            new OA\Response(response: 422, description: 'Invalid filter value or period'),
        ]
    )]
    public function index(IndexAuditLogRequest $request): JsonResponse
    {
        $organization = $this->activeOrganization();
        $this->authorize('viewAny', [AuditLog::class, $organization]);

        $user = $request->user();
        $accessibleRestaurantIds = RestaurantScope::accessibleRestaurantIds($user, $organization);

        $query = AuditLog::query()->where('organization_id', $organization->id);

        if ($accessibleRestaurantIds !== null) {
            $query->whereIn('restaurant_id', $accessibleRestaurantIds);
        }

        if ($request->filled('restaurant_id')) {
            $restaurantId = (int) $request->validated('restaurant_id');

            $inOrganization = Restaurant::query()->where('organization_id', $organization->id)->whereKey($restaurantId)->exists();
            $inScope = $accessibleRestaurantIds === null || in_array($restaurantId, $accessibleRestaurantIds, true);

            if (! $inOrganization || ! $inScope) {
                abort(404);
            }

            $query->where('restaurant_id', $restaurantId);
        }

        if ($request->filled('actor_user_id')) {
            $query->where('actor_user_id', (int) $request->validated('actor_user_id'));
        }

        if ($request->filled('event')) {
            $query->where('event', $request->validated('event'));
        }

        if ($request->filled('resource_type')) {
            $query->where('resource_type', $request->validated('resource_type'));
        }

        if ($request->filled('resource_id')) {
            $query->where('resource_id', (int) $request->validated('resource_id'));
        }

        $period = AuditLogPeriodResolver::resolve($request->validated('from'), $request->validated('to'));

        if ($period !== null) {
            $query->where('created_at', '>=', $period['from'])->where('created_at', '<', $period['toExclusive']);
        }

        $perPage = (int) ($request->validated('per_page') ?? self::DEFAULT_PER_PAGE);

        $logs = $query->with(['actor', 'restaurant'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage);

        return response()->json([
            'data' => [
                'audit_logs' => AuditLogResource::collection($logs->items()),
            ],
            'meta' => [
                'current_page' => $logs->currentPage(),
                'per_page' => $logs->perPage(),
                'total' => $logs->total(),
                'last_page' => $logs->lastPage(),
            ],
        ]);
    }

    /**
     * Resolve the active organization from the tenant context.
     */
    private function activeOrganization(): Organization
    {
        return Organization::query()->findOrFail($this->tenantContext->getOrganizationId());
    }
}
