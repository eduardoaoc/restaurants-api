<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Restaurant\StoreRestaurantRequest;
use App\Http\Requests\Api\V1\Restaurant\UpdateRestaurantRequest;
use App\Http\Resources\Api\V1\RestaurantResource;
use App\Models\Organization;
use App\Models\Restaurant;
use App\Models\RestaurantSettings;
use App\Models\User;
use App\Support\Restaurants\RestaurantScope;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OA;

class RestaurantController extends Controller
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    /**
     * List the restaurants belonging to the active organization, scoped to
     * what the requester can reach: an organization-wide owner sees every
     * restaurant, an operational staff member scoped to A+B sees exactly
     * A and B — never a restaurant they hold no restaurant_users link to,
     * even within the same organization.
     */
    #[OA\Get(
        path: '/api/v1/restaurants',
        operationId: 'restaurantsIndex',
        summary: 'List restaurants of the active organization',
        security: [['sessionCookie' => []]],
        tags: ['Restaurants'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'List of restaurants',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'data',
                            properties: [
                                new OA\Property(
                                    property: 'restaurants',
                                    type: 'array',
                                    items: new OA\Items(ref: '#/components/schemas/Restaurant')
                                ),
                            ],
                            type: 'object'
                        ),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'The user has no organization'),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $organization = $this->activeOrganization();

        $this->authorize('viewAny', [Restaurant::class, $organization]);

        $restaurants = $this->restaurantQuery($organization, $request->user())->get();

        return response()->json([
            'data' => [
                'restaurants' => RestaurantResource::collection($restaurants),
            ],
        ]);
    }

    /**
     * Create a restaurant under the active organization.
     */
    #[OA\Post(
        path: '/api/v1/restaurants',
        operationId: 'restaurantsStore',
        summary: 'Create a restaurant under the active organization',
        security: [['sessionCookie' => []]],
        tags: ['Restaurants'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name', 'slug'],
                properties: [
                    new OA\Property(property: 'name', type: 'string', example: 'Downtown Branch'),
                    new OA\Property(property: 'slug', type: 'string', example: 'downtown-branch'),
                    new OA\Property(property: 'status', type: 'string', example: 'active'),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Restaurant created successfully',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'Restaurant created successfully.'),
                        new OA\Property(
                            property: 'data',
                            properties: [
                                new OA\Property(property: 'restaurant', ref: '#/components/schemas/Restaurant'),
                            ],
                            type: 'object'
                        ),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'The user is not allowed to create restaurants'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function store(StoreRestaurantRequest $request): JsonResponse
    {
        $organization = $this->activeOrganization();

        $this->authorize('create', [Restaurant::class, $organization]);

        $restaurant = DB::transaction(function () use ($organization, $request) {
            $restaurant = $organization->restaurants()->create($request->validated());

            RestaurantSettings::createDefaultsFor($restaurant);

            return $restaurant;
        });

        return response()->json([
            'message' => 'Restaurant created successfully.',
            'data' => [
                'restaurant' => new RestaurantResource($restaurant),
            ],
        ], 201);
    }

    /**
     * Show a restaurant belonging to the active organization. Resolution
     * is scoped by RestaurantScope before authorization runs — a
     * restaurant outside the requester's scope (another restaurant of the
     * same organization the requester has no restaurant_users link to) is
     * 404 via findOrFail, never a Policy-level 403, matching every other
     * restaurant-scoped resource (Dashboard, Settings, Staff, ...).
     */
    #[OA\Get(
        path: '/api/v1/restaurants/{restaurant}',
        operationId: 'restaurantsShow',
        summary: 'Get a restaurant of the active organization',
        security: [['sessionCookie' => []]],
        tags: ['Restaurants'],
        parameters: [
            new OA\Parameter(name: 'restaurant', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'The restaurant',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'data',
                            properties: [
                                new OA\Property(property: 'restaurant', ref: '#/components/schemas/Restaurant'),
                            ],
                            type: 'object'
                        ),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'The user has no organization'),
            new OA\Response(response: 404, description: 'Restaurant not found, or outside the user\'s restaurant scope'),
        ]
    )]
    public function show(Request $request, int $restaurant): JsonResponse
    {
        $organization = $this->activeOrganization();

        $restaurantModel = $this->restaurantQuery($organization, $request->user())->findOrFail($restaurant);

        $this->authorize('view', $restaurantModel);

        return response()->json([
            'data' => [
                'restaurant' => new RestaurantResource($restaurantModel),
            ],
        ]);
    }

    /**
     * Update a restaurant belonging to the active organization. Same
     * scoped-resolution-before-authorization pattern as show().
     */
    #[OA\Patch(
        path: '/api/v1/restaurants/{restaurant}',
        operationId: 'restaurantsUpdate',
        summary: 'Update a restaurant of the active organization',
        security: [['sessionCookie' => []]],
        tags: ['Restaurants'],
        parameters: [
            new OA\Parameter(name: 'restaurant', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: false,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'name', type: 'string', example: 'Downtown Branch'),
                    new OA\Property(property: 'slug', type: 'string', example: 'downtown-branch'),
                    new OA\Property(property: 'status', type: 'string', example: 'active'),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Restaurant updated successfully',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'Restaurant updated successfully.'),
                        new OA\Property(
                            property: 'data',
                            properties: [
                                new OA\Property(property: 'restaurant', ref: '#/components/schemas/Restaurant'),
                            ],
                            type: 'object'
                        ),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'The user is not allowed to update this restaurant'),
            new OA\Response(response: 404, description: 'Restaurant not found, or outside the user\'s restaurant scope'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function update(UpdateRestaurantRequest $request, int $restaurant): JsonResponse
    {
        $organization = $this->activeOrganization();

        $restaurantModel = $this->restaurantQuery($organization, $request->user())->findOrFail($restaurant);

        $this->authorize('update', $restaurantModel);

        $restaurantModel->update($request->validated());

        return response()->json([
            'message' => 'Restaurant updated successfully.',
            'data' => [
                'restaurant' => new RestaurantResource($restaurantModel),
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
     * null from RestaurantScope means "every restaurant of the
     * organization" (organization-wide owner); otherwise restricted to the
     * requester's own restaurant_users membership. Same pattern as
     * KitchenController/RestaurantDashboardController/
     * RestaurantSettingsController — no second, parallel scoping
     * implementation.
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
