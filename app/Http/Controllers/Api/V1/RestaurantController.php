<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Restaurant\StoreRestaurantRequest;
use App\Http\Requests\Api\V1\Restaurant\UpdateRestaurantRequest;
use App\Http\Resources\Api\V1\RestaurantResource;
use App\Models\Organization;
use App\Models\Restaurant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

class RestaurantController extends Controller
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    /**
     * List the restaurants belonging to the active organization.
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
    public function index(): JsonResponse
    {
        $organization = $this->activeOrganization();

        $this->authorize('viewAny', [Restaurant::class, $organization]);

        $restaurants = $organization->restaurants()->get();

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
                    new OA\Property(property: 'timezone', type: 'string', example: 'America/Sao_Paulo'),
                    new OA\Property(property: 'default_locale', type: 'string', example: 'pt-BR'),
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

        $restaurant = $organization->restaurants()->create($request->validated());

        return response()->json([
            'message' => 'Restaurant created successfully.',
            'data' => [
                'restaurant' => new RestaurantResource($restaurant),
            ],
        ], 201);
    }

    /**
     * Show a restaurant belonging to the active organization.
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
            new OA\Response(response: 404, description: 'Restaurant not found'),
        ]
    )]
    public function show(int $restaurant): JsonResponse
    {
        $organization = $this->activeOrganization();

        $restaurantModel = $organization->restaurants()->findOrFail($restaurant);

        $this->authorize('view', $restaurantModel);

        return response()->json([
            'data' => [
                'restaurant' => new RestaurantResource($restaurantModel),
            ],
        ]);
    }

    /**
     * Update a restaurant belonging to the active organization.
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
                    new OA\Property(property: 'timezone', type: 'string', example: 'America/Sao_Paulo'),
                    new OA\Property(property: 'default_locale', type: 'string', example: 'pt-BR'),
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
            new OA\Response(response: 404, description: 'Restaurant not found'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function update(UpdateRestaurantRequest $request, int $restaurant): JsonResponse
    {
        $organization = $this->activeOrganization();

        $restaurantModel = $organization->restaurants()->findOrFail($restaurant);

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
}
