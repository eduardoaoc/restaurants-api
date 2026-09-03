<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Restaurants\UpdateRestaurantSettingsAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Restaurant\UpdateRestaurantSettingsRequest;
use App\Http\Resources\Api\V1\RestaurantSettingsResource;
use App\Models\Organization;
use App\Models\Restaurant;
use App\Models\User;
use App\Support\Restaurants\RestaurantScope;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class RestaurantSettingsController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly UpdateRestaurantSettingsAction $updateSettingsAction,
    ) {}

    /**
     * Get a restaurant's operational settings. Restaurant resolution is
     * scoped by RestaurantScope before authorization runs: a restaurant
     * outside the requester's scope resolves as 404, never 403 — same
     * convention as RestaurantDashboardController.
     */
    #[OA\Get(
        path: '/api/v1/restaurants/{restaurant}/settings',
        operationId: 'restaurantSettingsShow',
        summary: "Get a restaurant's operational settings",
        security: [['sessionCookie' => []]],
        tags: ['Restaurant Settings'],
        parameters: [
            new OA\Parameter(name: 'restaurant', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'The restaurant settings',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'data',
                            properties: [
                                new OA\Property(property: 'settings', ref: '#/components/schemas/RestaurantSettings'),
                            ],
                            type: 'object'
                        ),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'The user is not allowed to view this restaurant\'s settings'),
            new OA\Response(response: 404, description: 'Restaurant not found, or outside the user\'s restaurant scope'),
        ]
    )]
    public function show(Request $request, int $restaurant): JsonResponse
    {
        $organization = $this->activeOrganization();
        $restaurantModel = $this->restaurantQuery($organization, $request->user())->findOrFail($restaurant);

        $this->authorize('manageSettings', $restaurantModel);

        return response()->json([
            'data' => [
                'settings' => new RestaurantSettingsResource($restaurantModel->settings),
            ],
        ]);
    }

    /**
     * Partially update a restaurant's operational settings.
     */
    #[OA\Patch(
        path: '/api/v1/restaurants/{restaurant}/settings',
        operationId: 'restaurantSettingsUpdate',
        summary: "Update a restaurant's operational settings",
        security: [['sessionCookie' => []]],
        tags: ['Restaurant Settings'],
        parameters: [
            new OA\Parameter(name: 'restaurant', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: false,
            content: new OA\JsonContent(ref: '#/components/schemas/UpdateRestaurantSettingsRequest')
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Settings updated successfully',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'Settings updated successfully.'),
                        new OA\Property(
                            property: 'data',
                            properties: [
                                new OA\Property(property: 'settings', ref: '#/components/schemas/RestaurantSettings'),
                            ],
                            type: 'object'
                        ),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'The user is not allowed to update this restaurant\'s settings'),
            new OA\Response(response: 404, description: 'Restaurant not found, or outside the user\'s restaurant scope'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function update(UpdateRestaurantSettingsRequest $request, int $restaurant): JsonResponse
    {
        $organization = $this->activeOrganization();
        $restaurantModel = $this->restaurantQuery($organization, $request->user())->findOrFail($restaurant);

        $this->authorize('manageSettings', $restaurantModel);

        $settings = $this->updateSettingsAction->execute($restaurantModel->settings, $request->validated(), $request->user());

        return response()->json([
            'message' => 'Settings updated successfully.',
            'data' => [
                'settings' => new RestaurantSettingsResource($settings),
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

    /**
     * Restaurants of the active organization reachable by the requester —
     * an out-of-scope restaurant resolves as 404 via findOrFail, before
     * the permission check ever runs.
     */
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
