<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\FloorPlan\UpdateFloorPlanLayoutAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\FloorPlan\UpdateFloorPlanLayoutRequest;
use App\Http\Resources\Api\V1\FloorPlanResource;
use App\Models\Organization;
use App\Models\Restaurant;
use App\Models\User;
use App\Support\Restaurants\RestaurantScope;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * Serves the aggregated, editor-ready floor plan of a restaurant and
 * accepts the bulk layout save from the map editor (Bloco 1).
 */
class FloorPlanController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly UpdateFloorPlanLayoutAction $updateLayoutAction,
    ) {}

    #[OA\Get(
        path: '/api/v1/restaurants/{restaurant}/floor-plan',
        operationId: 'floorPlanShow',
        summary: "Get a restaurant's aggregated floor plan (floors, zones, tables)",
        security: [['sessionCookie' => []]],
        tags: ['Floor Plan'],
        parameters: [
            new OA\Parameter(name: 'restaurant', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'The floor plan',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'data',
                            properties: [new OA\Property(property: 'floor_plan', ref: '#/components/schemas/FloorPlan')],
                            type: 'object'
                        ),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'The user is not allowed to view this floor plan'),
            new OA\Response(response: 404, description: 'Restaurant not found, or outside the user\'s restaurant scope'),
        ]
    )]
    public function show(Request $request, int $restaurant): JsonResponse
    {
        $organization = $this->activeOrganization();
        $restaurantModel = $this->restaurantQuery($organization, $request->user())->findOrFail($restaurant);

        $this->authorize('viewFloorPlan', $restaurantModel);

        $restaurantModel->load(['floors' => function ($query) {
            $query->orderBy('sort_order');
        }, 'floors.zones' => function ($query) {
            $query->orderBy('sort_order');
        }, 'floors.zones.tables']);

        $unassignedTables = $restaurantModel->tables()->whereNull('zone_id')->get();

        return response()->json([
            'data' => ['floor_plan' => new FloorPlanResource($restaurantModel, $unassignedTables)],
        ]);
    }

    #[OA\Patch(
        path: '/api/v1/restaurants/{restaurant}/floor-plan/layout',
        operationId: 'floorPlanUpdateLayout',
        summary: 'Bulk-save table layout changes for a restaurant floor plan',
        security: [['sessionCookie' => []]],
        tags: ['Floor Plan'],
        parameters: [
            new OA\Parameter(name: 'restaurant', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/UpdateFloorPlanLayoutRequest')
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Layout updated successfully',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'Floor plan layout updated successfully.'),
                        new OA\Property(
                            property: 'data',
                            properties: [new OA\Property(property: 'tables_updated_count', type: 'integer', example: 5)],
                            type: 'object'
                        ),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'The user is not allowed to manage this floor plan'),
            new OA\Response(response: 404, description: 'Restaurant not found, or outside the user\'s restaurant scope'),
            new OA\Response(response: 422, description: 'Validation error — including a table or zone that does not belong to this restaurant. No changes are persisted.'),
        ]
    )]
    public function updateLayout(UpdateFloorPlanLayoutRequest $request, int $restaurant): JsonResponse
    {
        $organization = $this->activeOrganization();
        $restaurantModel = $this->restaurantQuery($organization, $request->user())->findOrFail($restaurant);

        $this->authorize('manageFloorPlan', $restaurantModel);

        $count = $this->updateLayoutAction->execute($restaurantModel, $request->validated()['tables'], $request->user());

        return response()->json([
            'message' => 'Floor plan layout updated successfully.',
            'data' => ['tables_updated_count' => $count],
        ]);
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
}
