<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Menu\StoreMenuRequest;
use App\Http\Requests\Api\V1\Menu\UpdateMenuRequest;
use App\Http\Resources\Api\V1\MenuResource;
use App\Models\Menu;
use App\Models\Organization;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

class MenuController extends Controller
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    /**
     * Show the menu of a restaurant belonging to the active organization.
     */
    #[OA\Get(
        path: '/api/v1/restaurants/{restaurant}/menu',
        operationId: 'menuShow',
        summary: 'Get the menu of a restaurant',
        security: [['sessionCookie' => []]],
        tags: ['Menu'],
        parameters: [
            new OA\Parameter(name: 'restaurant', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'The menu',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'data',
                            properties: [
                                new OA\Property(property: 'menu', ref: '#/components/schemas/Menu'),
                            ],
                            type: 'object'
                        ),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'The user is not allowed to view this menu'),
            new OA\Response(response: 404, description: 'Restaurant or menu not found'),
        ]
    )]
    public function show(int $restaurant): JsonResponse
    {
        $organization = $this->activeOrganization();
        $restaurantModel = $organization->restaurants()->findOrFail($restaurant);

        $this->authorize('view', [Menu::class, $restaurantModel]);

        $menu = $restaurantModel->menu;

        if (! $menu) {
            abort(404, 'This restaurant does not have a menu yet.');
        }

        return response()->json([
            'data' => [
                'menu' => new MenuResource($menu),
            ],
        ]);
    }

    /**
     * Create the menu of a restaurant belonging to the active organization.
     */
    #[OA\Post(
        path: '/api/v1/restaurants/{restaurant}/menu',
        operationId: 'menuStore',
        summary: 'Create the menu of a restaurant',
        security: [['sessionCookie' => []]],
        tags: ['Menu'],
        parameters: [
            new OA\Parameter(name: 'restaurant', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: false,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'name', type: 'string', example: 'Main Menu'),
                    new OA\Property(property: 'status', type: 'string', example: 'active'),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Menu created successfully',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'Menu created successfully.'),
                        new OA\Property(
                            property: 'data',
                            properties: [
                                new OA\Property(property: 'menu', ref: '#/components/schemas/Menu'),
                            ],
                            type: 'object'
                        ),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'The user is not allowed to create this menu'),
            new OA\Response(response: 404, description: 'Restaurant not found'),
            new OA\Response(response: 409, description: 'This restaurant already has a menu'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function store(StoreMenuRequest $request, int $restaurant): JsonResponse
    {
        $organization = $this->activeOrganization();
        $restaurantModel = $organization->restaurants()->findOrFail($restaurant);

        $this->authorize('create', [Menu::class, $restaurantModel]);

        if ($restaurantModel->menu) {
            abort(409, 'This restaurant already has a menu.');
        }

        $menu = $restaurantModel->menu()->create([
            'name' => $request->validated('name') ?? 'Main Menu',
            'status' => $request->validated('status') ?? 'active',
        ]);

        return response()->json([
            'message' => 'Menu created successfully.',
            'data' => [
                'menu' => new MenuResource($menu),
            ],
        ], 201);
    }

    /**
     * Update the menu of a restaurant belonging to the active organization.
     */
    #[OA\Patch(
        path: '/api/v1/restaurants/{restaurant}/menu',
        operationId: 'menuUpdate',
        summary: 'Update the menu of a restaurant',
        security: [['sessionCookie' => []]],
        tags: ['Menu'],
        parameters: [
            new OA\Parameter(name: 'restaurant', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: false,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'name', type: 'string', example: 'Main Menu'),
                    new OA\Property(property: 'status', type: 'string', example: 'active'),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Menu updated successfully',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'Menu updated successfully.'),
                        new OA\Property(
                            property: 'data',
                            properties: [
                                new OA\Property(property: 'menu', ref: '#/components/schemas/Menu'),
                            ],
                            type: 'object'
                        ),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'The user is not allowed to update this menu'),
            new OA\Response(response: 404, description: 'Restaurant or menu not found'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function update(UpdateMenuRequest $request, int $restaurant): JsonResponse
    {
        $organization = $this->activeOrganization();
        $restaurantModel = $organization->restaurants()->findOrFail($restaurant);

        $this->authorize('update', [Menu::class, $restaurantModel]);

        $menu = $restaurantModel->menu;

        if (! $menu) {
            abort(404, 'This restaurant does not have a menu yet.');
        }

        $menu->update($request->validated());

        return response()->json([
            'message' => 'Menu updated successfully.',
            'data' => [
                'menu' => new MenuResource($menu),
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
