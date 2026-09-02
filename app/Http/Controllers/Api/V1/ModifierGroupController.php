<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Catalog\CreateModifierGroupAction;
use App\Actions\Catalog\UpdateModifierGroupAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ModifierGroup\StoreModifierGroupRequest;
use App\Http\Requests\Api\V1\ModifierGroup\UpdateModifierGroupRequest;
use App\Http\Resources\Api\V1\ModifierGroupResource;
use App\Models\ModifierGroup;
use App\Models\Organization;
use App\Models\RestaurantProduct;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

class ModifierGroupController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly CreateModifierGroupAction $createModifierGroupAction,
        private readonly UpdateModifierGroupAction $updateModifierGroupAction,
    ) {}

    /**
     * List the modifier groups of a restaurant product.
     */
    #[OA\Get(
        path: '/api/v1/restaurant-products/{restaurantProduct}/modifier-groups',
        operationId: 'modifierGroupsIndex',
        summary: 'List the modifier groups of a restaurant product',
        security: [['sessionCookie' => []]],
        tags: ['Modifiers'],
        parameters: [
            new OA\Parameter(name: 'restaurantProduct', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'List of modifier groups',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'data',
                            properties: [
                                new OA\Property(
                                    property: 'modifier_groups',
                                    type: 'array',
                                    items: new OA\Items(ref: '#/components/schemas/ModifierGroup')
                                ),
                            ],
                            type: 'object'
                        ),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'The user is not allowed to view modifier groups'),
            new OA\Response(response: 404, description: 'Restaurant product not found'),
        ]
    )]
    public function index(int $restaurantProduct): JsonResponse
    {
        $organization = $this->activeOrganization();
        $restaurantProductModel = $this->restaurantProductQuery($organization)->findOrFail($restaurantProduct);

        $this->authorize('viewAny', [ModifierGroup::class, $restaurantProductModel]);

        $modifierGroups = $restaurantProductModel->modifierGroups()
            ->with('translations')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return response()->json([
            'data' => [
                'modifier_groups' => ModifierGroupResource::collection($modifierGroups),
            ],
        ]);
    }

    /**
     * Create a modifier group under a restaurant product.
     */
    #[OA\Post(
        path: '/api/v1/restaurant-products/{restaurantProduct}/modifier-groups',
        operationId: 'modifierGroupsStore',
        summary: 'Create a modifier group under a restaurant product',
        security: [['sessionCookie' => []]],
        tags: ['Modifiers'],
        parameters: [
            new OA\Parameter(name: 'restaurantProduct', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['max_select', 'translations'],
                properties: [
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
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Modifier group created successfully',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'Modifier group created successfully.'),
                        new OA\Property(
                            property: 'data',
                            properties: [
                                new OA\Property(property: 'modifier_group', ref: '#/components/schemas/ModifierGroup'),
                            ],
                            type: 'object'
                        ),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'The user is not allowed to create modifier groups'),
            new OA\Response(response: 404, description: 'Restaurant product not found'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function store(StoreModifierGroupRequest $request, int $restaurantProduct): JsonResponse
    {
        $organization = $this->activeOrganization();
        $restaurantProductModel = $this->restaurantProductQuery($organization)->findOrFail($restaurantProduct);

        $this->authorize('create', [ModifierGroup::class, $restaurantProductModel]);

        $modifierGroup = $this->createModifierGroupAction->execute($restaurantProductModel, $request->validated());

        return response()->json([
            'message' => 'Modifier group created successfully.',
            'data' => [
                'modifier_group' => new ModifierGroupResource($modifierGroup),
            ],
        ], 201);
    }

    /**
     * Show a modifier group of the active organization.
     */
    #[OA\Get(
        path: '/api/v1/modifier-groups/{modifierGroup}',
        operationId: 'modifierGroupsShow',
        summary: 'Get a modifier group of the active organization',
        security: [['sessionCookie' => []]],
        tags: ['Modifiers'],
        parameters: [
            new OA\Parameter(name: 'modifierGroup', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'The modifier group',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'data',
                            properties: [
                                new OA\Property(property: 'modifier_group', ref: '#/components/schemas/ModifierGroup'),
                            ],
                            type: 'object'
                        ),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'The user is not allowed to view this modifier group'),
            new OA\Response(response: 404, description: 'Modifier group not found'),
        ]
    )]
    public function show(int $modifierGroup): JsonResponse
    {
        $organization = $this->activeOrganization();
        $modifierGroupModel = $this->modifierGroupQuery($organization)->findOrFail($modifierGroup);

        $this->authorize('view', $modifierGroupModel);

        return response()->json([
            'data' => [
                'modifier_group' => new ModifierGroupResource($modifierGroupModel),
            ],
        ]);
    }

    /**
     * Update a modifier group of the active organization.
     */
    #[OA\Patch(
        path: '/api/v1/modifier-groups/{modifierGroup}',
        operationId: 'modifierGroupsUpdate',
        summary: 'Update a modifier group of the active organization',
        security: [['sessionCookie' => []]],
        tags: ['Modifiers'],
        parameters: [
            new OA\Parameter(name: 'modifierGroup', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: false,
            content: new OA\JsonContent(
                properties: [
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
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Modifier group updated successfully',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'Modifier group updated successfully.'),
                        new OA\Property(
                            property: 'data',
                            properties: [
                                new OA\Property(property: 'modifier_group', ref: '#/components/schemas/ModifierGroup'),
                            ],
                            type: 'object'
                        ),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'The user is not allowed to update this modifier group'),
            new OA\Response(response: 404, description: 'Modifier group not found'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function update(UpdateModifierGroupRequest $request, int $modifierGroup): JsonResponse
    {
        $organization = $this->activeOrganization();
        $modifierGroupModel = $this->modifierGroupQuery($organization)->findOrFail($modifierGroup);

        $this->authorize('update', $modifierGroupModel);

        $modifierGroupModel = $this->updateModifierGroupAction->execute($modifierGroupModel, $request->validated());

        return response()->json([
            'message' => 'Modifier group updated successfully.',
            'data' => [
                'modifier_group' => new ModifierGroupResource($modifierGroupModel),
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
     * Restaurant products scoped to the active organization, via restaurant.
     */
    private function restaurantProductQuery(Organization $organization): Builder
    {
        return RestaurantProduct::query()->whereHas('restaurant', function ($query) use ($organization) {
            $query->where('organization_id', $organization->id);
        });
    }

    /**
     * Modifier groups scoped to the active organization, via restaurant product -> restaurant.
     */
    private function modifierGroupQuery(Organization $organization): Builder
    {
        return ModifierGroup::query()
            ->whereHas('restaurantProduct.restaurant', function ($query) use ($organization) {
                $query->where('organization_id', $organization->id);
            })
            ->with('translations');
    }
}
