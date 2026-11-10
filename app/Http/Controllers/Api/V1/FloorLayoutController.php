<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\FloorLayout\ReplaceRestaurantFloorLayoutAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\FloorLayout\ReplaceRestaurantFloorLayoutRequest;
use App\Http\Resources\Api\V1\RestaurantFloorLayoutResource;
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
 * Reads and replaces the persisted 3D floor layout document of a restaurant.
 * Same tenant scoping and policies as FloorPlanController: viewing needs
 * viewFloorPlan (manage_tables/close_bill), replacing needs manageFloorPlan
 * (manage_floor_plan — owner/manager).
 */
class FloorLayoutController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly ReplaceRestaurantFloorLayoutAction $replaceAction,
    ) {}

    #[OA\Get(
        path: '/api/v1/restaurants/{restaurant}/floor-layout',
        operationId: 'floorLayoutShow',
        summary: "Get a restaurant's saved 3D floor layout (null when none was ever saved)",
        security: [['sessionCookie' => []]],
        tags: ['Floor Layout'],
        parameters: [
            new OA\Parameter(name: 'restaurant', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: '{data: {floor_layout: {version, floor, tables, updated_at} | null}}'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'The user is not allowed to view this floor layout'),
            new OA\Response(response: 404, description: 'Restaurant not found, or outside the user\'s scope'),
        ]
    )]
    public function show(Request $request, int $restaurant): JsonResponse
    {
        $restaurantModel = $this->restaurantQuery($request->user())->findOrFail($restaurant);

        $this->authorize('viewFloorPlan', $restaurantModel);

        $layout = $restaurantModel->floorLayout;

        return response()->json([
            'data' => ['floor_layout' => $layout ? new RestaurantFloorLayoutResource($layout) : null],
        ]);
    }

    #[OA\Put(
        path: '/api/v1/restaurants/{restaurant}/floor-layout',
        operationId: 'floorLayoutReplace',
        summary: "Replace a restaurant's whole 3D floor layout (atomic, full document, version 1)",
        security: [['sessionCookie' => []]],
        tags: ['Floor Layout'],
        parameters: [
            new OA\Parameter(name: 'restaurant', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            description: '{version: 1, floor: {width, depth, gridStep}, entrance?: {x, z}, tables: [{id (uuid), backendTableId?: integer|null, number, shape, seats, position: {x, z}, rotation}]}'
        )),
        responses: [
            new OA\Response(response: 200, description: 'Saved; returns the canonical stored document'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'The user is not allowed to manage this floor layout'),
            new OA\Response(response: 404, description: 'Restaurant not found, or outside the user\'s scope'),
            new OA\Response(response: 422, description: 'Validation error (including unsupported version). Nothing is persisted.'),
        ]
    )]
    public function update(ReplaceRestaurantFloorLayoutRequest $request, int $restaurant): JsonResponse
    {
        $restaurantModel = $this->restaurantQuery($request->user())->findOrFail($restaurant);

        $this->authorize('manageFloorPlan', $restaurantModel);

        $layout = $this->replaceAction->execute($restaurantModel, $request->validated(), $request->user());

        return response()->json([
            'message' => 'Floor layout saved successfully.',
            'data' => ['floor_layout' => new RestaurantFloorLayoutResource($layout)],
        ]);
    }

    private function restaurantQuery(User $requester): Builder
    {
        $organization = Organization::query()->findOrFail($this->tenantContext->getOrganizationId());
        $accessibleRestaurantIds = RestaurantScope::accessibleRestaurantIds($requester, $organization);

        $query = Restaurant::query()->where('organization_id', $organization->id);

        if ($accessibleRestaurantIds !== null) {
            $query->whereIn('id', $accessibleRestaurantIds);
        }

        return $query;
    }
}
