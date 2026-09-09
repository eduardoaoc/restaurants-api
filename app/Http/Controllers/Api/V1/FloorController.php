<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\FloorPlan\FloorHasZonesException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Floor\StoreFloorRequest;
use App\Http\Requests\Api\V1\Floor\UpdateFloorRequest;
use App\Http\Resources\Api\V1\FloorResource;
use App\Models\AuditLog;
use App\Models\Floor;
use App\Models\Organization;
use App\Models\Restaurant;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Restaurants\RestaurantScope;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * Manages Floors (Bloco 1: Floor Plan). Viewing requires manage_tables OR
 * close_bill (see FloorPolicy); creating/editing/deleting requires the
 * dedicated manage_floor_plan permission — never manage_tables alone,
 * which a waiter also holds.
 */
class FloorController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly AuditLogger $auditLogger,
    ) {}

    #[OA\Get(
        path: '/api/v1/restaurants/{restaurant}/floors',
        operationId: 'floorsIndex',
        summary: 'List the floors of a restaurant',
        security: [['sessionCookie' => []]],
        tags: ['Floor Plan'],
        parameters: [
            new OA\Parameter(name: 'restaurant', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'List of floors',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'data',
                            properties: [
                                new OA\Property(property: 'floors', type: 'array', items: new OA\Items(ref: '#/components/schemas/Floor')),
                            ],
                            type: 'object'
                        ),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'The user is not allowed to view floors'),
            new OA\Response(response: 404, description: 'Restaurant not found, or outside the user\'s restaurant scope'),
        ]
    )]
    public function index(Request $request, int $restaurant): JsonResponse
    {
        $organization = $this->activeOrganization();
        $restaurantModel = $this->restaurantQuery($organization, $request->user())->findOrFail($restaurant);

        $this->authorize('viewAny', [Floor::class, $restaurantModel]);

        $floors = $restaurantModel->floors()->orderBy('sort_order')->get();

        return response()->json([
            'data' => [
                'floors' => FloorResource::collection($floors),
            ],
        ]);
    }

    #[OA\Post(
        path: '/api/v1/restaurants/{restaurant}/floors',
        operationId: 'floorsStore',
        summary: 'Create a floor under a restaurant',
        security: [['sessionCookie' => []]],
        tags: ['Floor Plan'],
        parameters: [
            new OA\Parameter(name: 'restaurant', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name'],
                properties: [
                    new OA\Property(property: 'name', type: 'string', example: 'Ground Floor'),
                    new OA\Property(property: 'sort_order', type: 'integer', example: 0),
                    new OA\Property(property: 'is_active', type: 'boolean', example: true),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Floor created successfully',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'Floor created successfully.'),
                        new OA\Property(
                            property: 'data',
                            properties: [new OA\Property(property: 'floor', ref: '#/components/schemas/Floor')],
                            type: 'object'
                        ),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'The user is not allowed to create floors'),
            new OA\Response(response: 404, description: 'Restaurant not found, or outside the user\'s restaurant scope'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function store(StoreFloorRequest $request, int $restaurant): JsonResponse
    {
        $organization = $this->activeOrganization();
        $restaurantModel = $this->restaurantQuery($organization, $request->user())->findOrFail($restaurant);

        $this->authorize('create', [Floor::class, $restaurantModel]);

        // Merge, not rely on the DB column default: create() does not
        // re-fetch the row, so an omitted field would otherwise come back
        // null in the response/resource instead of the column's real default.
        $floor = $restaurantModel->floors()->create([
            'sort_order' => 0,
            'is_active' => true,
            ...$request->validated(),
        ]);

        $this->auditLogger->log(
            organizationId: $organization->id,
            restaurantId: $restaurantModel->id,
            actorType: AuditLog::ACTOR_USER,
            actor: $request->user(),
            event: AuditLog::EVENT_FLOOR_CREATED,
            resourceType: AuditLog::RESOURCE_FLOOR,
            resourceId: $floor->id,
            metadata: ['name' => $floor->name],
        );

        return response()->json([
            'message' => 'Floor created successfully.',
            'data' => ['floor' => new FloorResource($floor)],
        ], 201);
    }

    #[OA\Get(
        path: '/api/v1/floors/{floor}',
        operationId: 'floorsShow',
        summary: 'Get a floor of the active organization',
        security: [['sessionCookie' => []]],
        tags: ['Floor Plan'],
        parameters: [
            new OA\Parameter(name: 'floor', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'The floor',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'data',
                            properties: [new OA\Property(property: 'floor', ref: '#/components/schemas/Floor')],
                            type: 'object'
                        ),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'The user is not allowed to view this floor'),
            new OA\Response(response: 404, description: 'Floor not found'),
        ]
    )]
    public function show(Request $request, int $floor): JsonResponse
    {
        $organization = $this->activeOrganization();
        $floorModel = $this->floorQuery($organization, $request->user())->findOrFail($floor);

        $this->authorize('view', $floorModel);

        return response()->json(['data' => ['floor' => new FloorResource($floorModel)]]);
    }

    #[OA\Patch(
        path: '/api/v1/floors/{floor}',
        operationId: 'floorsUpdate',
        summary: 'Update a floor of the active organization',
        security: [['sessionCookie' => []]],
        tags: ['Floor Plan'],
        parameters: [
            new OA\Parameter(name: 'floor', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: false,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'name', type: 'string', example: 'Ground Floor'),
                    new OA\Property(property: 'sort_order', type: 'integer', example: 0),
                    new OA\Property(property: 'is_active', type: 'boolean', example: true),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Floor updated successfully',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'Floor updated successfully.'),
                        new OA\Property(
                            property: 'data',
                            properties: [new OA\Property(property: 'floor', ref: '#/components/schemas/Floor')],
                            type: 'object'
                        ),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'The user is not allowed to update this floor'),
            new OA\Response(response: 404, description: 'Floor not found'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function update(UpdateFloorRequest $request, int $floor): JsonResponse
    {
        $organization = $this->activeOrganization();
        $floorModel = $this->floorQuery($organization, $request->user())->findOrFail($floor);

        $this->authorize('update', $floorModel);

        $data = $request->validated();
        $before = $floorModel->only(array_keys($data));

        $floorModel->update($data);

        $this->auditLogger->log(
            organizationId: $organization->id,
            restaurantId: $floorModel->restaurant_id,
            actorType: AuditLog::ACTOR_USER,
            actor: $request->user(),
            event: AuditLog::EVENT_FLOOR_UPDATED,
            resourceType: AuditLog::RESOURCE_FLOOR,
            resourceId: $floorModel->id,
            changes: collect($data)->mapWithKeys(fn ($value, $key) => [$key => ['old' => $before[$key] ?? null, 'new' => $value]])->all(),
        );

        return response()->json([
            'message' => 'Floor updated successfully.',
            'data' => ['floor' => new FloorResource($floorModel)],
        ]);
    }

    #[OA\Delete(
        path: '/api/v1/floors/{floor}',
        operationId: 'floorsDestroy',
        summary: 'Delete a floor of the active organization',
        security: [['sessionCookie' => []]],
        tags: ['Floor Plan'],
        parameters: [
            new OA\Parameter(name: 'floor', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Floor deleted successfully'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'The user is not allowed to delete this floor'),
            new OA\Response(response: 404, description: 'Floor not found'),
            new OA\Response(response: 409, description: 'FLOOR_HAS_ZONES — the floor still has zones assigned to it', content: new OA\JsonContent(ref: '#/components/schemas/PublicApiError')),
        ]
    )]
    public function destroy(Request $request, int $floor): JsonResponse
    {
        $organization = $this->activeOrganization();
        $floorModel = $this->floorQuery($organization, $request->user())->findOrFail($floor);

        $this->authorize('delete', $floorModel);

        if ($floorModel->zones()->exists()) {
            throw new FloorHasZonesException;
        }

        $floorModel->delete();

        $this->auditLogger->log(
            organizationId: $organization->id,
            restaurantId: $floorModel->restaurant_id,
            actorType: AuditLog::ACTOR_USER,
            actor: $request->user(),
            event: AuditLog::EVENT_FLOOR_DELETED,
            resourceType: AuditLog::RESOURCE_FLOOR,
            resourceId: $floorModel->id,
            metadata: ['name' => $floorModel->name],
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

    private function floorQuery(Organization $organization, User $requester): Builder
    {
        $accessibleRestaurantIds = RestaurantScope::accessibleRestaurantIds($requester, $organization);

        $query = Floor::query()->whereHas('restaurant', function (Builder $query) use ($organization) {
            $query->where('organization_id', $organization->id);
        });

        if ($accessibleRestaurantIds !== null) {
            $query->whereIn('restaurant_id', $accessibleRestaurantIds);
        }

        return $query;
    }
}
