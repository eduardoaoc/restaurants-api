<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\FloorPlan\ZoneHasTablesException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Zone\StoreZoneRequest;
use App\Http\Requests\Api\V1\Zone\UpdateZoneRequest;
use App\Http\Resources\Api\V1\ZoneResource;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\Restaurant;
use App\Models\User;
use App\Models\Zone;
use App\Support\Audit\AuditLogger;
use App\Support\Restaurants\RestaurantScope;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * Manages Zones (Bloco 1: Floor Plan). Same view/manage permission split as
 * FloorController — see FloorPolicy/ZonePolicy.
 */
class ZoneController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly AuditLogger $auditLogger,
    ) {}

    #[OA\Get(
        path: '/api/v1/restaurants/{restaurant}/zones',
        operationId: 'zonesIndex',
        summary: 'List the zones of a restaurant',
        security: [['sessionCookie' => []]],
        tags: ['Floor Plan'],
        parameters: [
            new OA\Parameter(name: 'restaurant', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'List of zones',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'data',
                            properties: [
                                new OA\Property(property: 'zones', type: 'array', items: new OA\Items(ref: '#/components/schemas/Zone')),
                            ],
                            type: 'object'
                        ),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'The user is not allowed to view zones'),
            new OA\Response(response: 404, description: 'Restaurant not found, or outside the user\'s restaurant scope'),
        ]
    )]
    public function index(Request $request, int $restaurant): JsonResponse
    {
        $organization = $this->activeOrganization();
        $restaurantModel = $this->restaurantQuery($organization, $request->user())->findOrFail($restaurant);

        $this->authorize('viewAny', [Zone::class, $restaurantModel]);

        $zones = $restaurantModel->zones()->orderBy('sort_order')->get();

        return response()->json(['data' => ['zones' => ZoneResource::collection($zones)]]);
    }

    #[OA\Post(
        path: '/api/v1/restaurants/{restaurant}/zones',
        operationId: 'zonesStore',
        summary: 'Create a zone under a restaurant',
        security: [['sessionCookie' => []]],
        tags: ['Floor Plan'],
        parameters: [
            new OA\Parameter(name: 'restaurant', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name', 'floor_id'],
                properties: [
                    new OA\Property(property: 'name', type: 'string', example: 'Interior'),
                    new OA\Property(property: 'floor_id', type: 'integer', example: 1),
                    new OA\Property(property: 'sort_order', type: 'integer', example: 0),
                    new OA\Property(property: 'is_active', type: 'boolean', example: true),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Zone created successfully',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'Zone created successfully.'),
                        new OA\Property(
                            property: 'data',
                            properties: [new OA\Property(property: 'zone', ref: '#/components/schemas/Zone')],
                            type: 'object'
                        ),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'The user is not allowed to create zones'),
            new OA\Response(response: 404, description: 'Restaurant not found, or outside the user\'s restaurant scope'),
            new OA\Response(response: 422, description: 'Validation error, or floor_id does not belong to this restaurant'),
        ]
    )]
    public function store(StoreZoneRequest $request, int $restaurant): JsonResponse
    {
        $organization = $this->activeOrganization();
        $restaurantModel = $this->restaurantQuery($organization, $request->user())->findOrFail($restaurant);

        $this->authorize('create', [Zone::class, $restaurantModel]);

        // See FloorController::store for why the defaults are merged here
        // rather than left to the DB column default.
        $zone = $restaurantModel->zones()->create([
            'sort_order' => 0,
            'is_active' => true,
            ...$request->validated(),
        ]);

        $this->auditLogger->log(
            organizationId: $organization->id,
            restaurantId: $restaurantModel->id,
            actorType: AuditLog::ACTOR_USER,
            actor: $request->user(),
            event: AuditLog::EVENT_ZONE_CREATED,
            resourceType: AuditLog::RESOURCE_ZONE,
            resourceId: $zone->id,
            metadata: ['name' => $zone->name, 'floor_id' => $zone->floor_id],
        );

        return response()->json([
            'message' => 'Zone created successfully.',
            'data' => ['zone' => new ZoneResource($zone)],
        ], 201);
    }

    #[OA\Get(
        path: '/api/v1/zones/{zone}',
        operationId: 'zonesShow',
        summary: 'Get a zone of the active organization',
        security: [['sessionCookie' => []]],
        tags: ['Floor Plan'],
        parameters: [
            new OA\Parameter(name: 'zone', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'The zone',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'data',
                            properties: [new OA\Property(property: 'zone', ref: '#/components/schemas/Zone')],
                            type: 'object'
                        ),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'The user is not allowed to view this zone'),
            new OA\Response(response: 404, description: 'Zone not found'),
        ]
    )]
    public function show(Request $request, int $zone): JsonResponse
    {
        $organization = $this->activeOrganization();
        $zoneModel = $this->zoneQuery($organization, $request->user())->findOrFail($zone);

        $this->authorize('view', $zoneModel);

        return response()->json(['data' => ['zone' => new ZoneResource($zoneModel)]]);
    }

    #[OA\Patch(
        path: '/api/v1/zones/{zone}',
        operationId: 'zonesUpdate',
        summary: 'Update a zone of the active organization',
        security: [['sessionCookie' => []]],
        tags: ['Floor Plan'],
        parameters: [
            new OA\Parameter(name: 'zone', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: false,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'name', type: 'string', example: 'Interior'),
                    new OA\Property(property: 'floor_id', type: 'integer', example: 1),
                    new OA\Property(property: 'sort_order', type: 'integer', example: 0),
                    new OA\Property(property: 'is_active', type: 'boolean', example: true),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Zone updated successfully',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'Zone updated successfully.'),
                        new OA\Property(
                            property: 'data',
                            properties: [new OA\Property(property: 'zone', ref: '#/components/schemas/Zone')],
                            type: 'object'
                        ),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'The user is not allowed to update this zone'),
            new OA\Response(response: 404, description: 'Zone not found'),
            new OA\Response(response: 422, description: 'Validation error, or floor_id does not belong to this restaurant'),
        ]
    )]
    public function update(UpdateZoneRequest $request, int $zone): JsonResponse
    {
        $organization = $this->activeOrganization();
        $zoneModel = $this->zoneQuery($organization, $request->user())->findOrFail($zone);

        $this->authorize('update', $zoneModel);

        $data = $request->validated();
        $before = $zoneModel->only(array_keys($data));

        $zoneModel->update($data);

        $this->auditLogger->log(
            organizationId: $organization->id,
            restaurantId: $zoneModel->restaurant_id,
            actorType: AuditLog::ACTOR_USER,
            actor: $request->user(),
            event: AuditLog::EVENT_ZONE_UPDATED,
            resourceType: AuditLog::RESOURCE_ZONE,
            resourceId: $zoneModel->id,
            changes: collect($data)->mapWithKeys(fn ($value, $key) => [$key => ['old' => $before[$key] ?? null, 'new' => $value]])->all(),
        );

        return response()->json([
            'message' => 'Zone updated successfully.',
            'data' => ['zone' => new ZoneResource($zoneModel)],
        ]);
    }

    #[OA\Delete(
        path: '/api/v1/zones/{zone}',
        operationId: 'zonesDestroy',
        summary: 'Delete a zone of the active organization',
        security: [['sessionCookie' => []]],
        tags: ['Floor Plan'],
        parameters: [
            new OA\Parameter(name: 'zone', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Zone deleted successfully'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'The user is not allowed to delete this zone'),
            new OA\Response(response: 404, description: 'Zone not found'),
            new OA\Response(response: 409, description: 'ZONE_HAS_TABLES — the zone still has tables assigned to it', content: new OA\JsonContent(ref: '#/components/schemas/PublicApiError')),
        ]
    )]
    public function destroy(Request $request, int $zone): JsonResponse
    {
        $organization = $this->activeOrganization();
        $zoneModel = $this->zoneQuery($organization, $request->user())->findOrFail($zone);

        $this->authorize('delete', $zoneModel);

        if ($zoneModel->tables()->exists()) {
            throw new ZoneHasTablesException;
        }

        $zoneModel->delete();

        $this->auditLogger->log(
            organizationId: $organization->id,
            restaurantId: $zoneModel->restaurant_id,
            actorType: AuditLog::ACTOR_USER,
            actor: $request->user(),
            event: AuditLog::EVENT_ZONE_DELETED,
            resourceType: AuditLog::RESOURCE_ZONE,
            resourceId: $zoneModel->id,
            metadata: ['name' => $zoneModel->name],
        );

        return response()->json(null, 204);
    }

    private function activeOrganization(): Organization
    {
        return Organization::query()->findOrFail($this->tenantContext->getOrganizationId());
    }

    private function restaurantQuery(Organization $organization, User $requester): Builder
    {
        $accessibleRestaurantIds = RestaurantScope::accessibleRestaurantIds($requester, $organization);

        $query = Restaurant::query()->where('organization_id', $organization->id);

        if ($accessibleRestaurantIds !== null) {
            $query->whereIn('id', $accessibleRestaurantIds);
        }

        return $query;
    }

    private function zoneQuery(Organization $organization, User $requester): Builder
    {
        $accessibleRestaurantIds = RestaurantScope::accessibleRestaurantIds($requester, $organization);

        $query = Zone::query()->whereHas('restaurant', function (Builder $query) use ($organization) {
            $query->where('organization_id', $organization->id);
        });

        if ($accessibleRestaurantIds !== null) {
            $query->whereIn('restaurant_id', $accessibleRestaurantIds);
        }

        return $query;
    }
}
