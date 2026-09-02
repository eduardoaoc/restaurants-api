<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Catalog\CreateModifierOptionAction;
use App\Actions\Catalog\UpdateModifierOptionAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ModifierOption\StoreModifierOptionRequest;
use App\Http\Requests\Api\V1\ModifierOption\UpdateModifierOptionRequest;
use App\Http\Resources\Api\V1\ModifierOptionResource;
use App\Models\ModifierGroup;
use App\Models\ModifierOption;
use App\Models\Organization;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

class ModifierOptionController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly CreateModifierOptionAction $createModifierOptionAction,
        private readonly UpdateModifierOptionAction $updateModifierOptionAction,
    ) {}

    /**
     * List the options of a modifier group.
     */
    #[OA\Get(
        path: '/api/v1/modifier-groups/{modifierGroup}/options',
        operationId: 'modifierOptionsIndex',
        summary: 'List the options of a modifier group',
        security: [['sessionCookie' => []]],
        tags: ['Modifiers'],
        parameters: [
            new OA\Parameter(name: 'modifierGroup', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'List of modifier options',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'data',
                            properties: [
                                new OA\Property(
                                    property: 'modifier_options',
                                    type: 'array',
                                    items: new OA\Items(ref: '#/components/schemas/ModifierOption')
                                ),
                            ],
                            type: 'object'
                        ),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'The user is not allowed to view modifier options'),
            new OA\Response(response: 404, description: 'Modifier group not found'),
        ]
    )]
    public function index(int $modifierGroup): JsonResponse
    {
        $organization = $this->activeOrganization();
        $modifierGroupModel = $this->modifierGroupQuery($organization)->findOrFail($modifierGroup);

        $this->authorize('viewAny', [ModifierOption::class, $modifierGroupModel]);

        $options = $modifierGroupModel->options()
            ->with('translations')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return response()->json([
            'data' => [
                'modifier_options' => ModifierOptionResource::collection($options),
            ],
        ]);
    }

    /**
     * Create an option under a modifier group.
     */
    #[OA\Post(
        path: '/api/v1/modifier-groups/{modifierGroup}/options',
        operationId: 'modifierOptionsStore',
        summary: 'Create an option under a modifier group',
        security: [['sessionCookie' => []]],
        tags: ['Modifiers'],
        parameters: [
            new OA\Parameter(name: 'modifierGroup', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['internal_name', 'translations'],
                properties: [
                    new OA\Property(property: 'internal_name', type: 'string', example: 'Bacon'),
                    new OA\Property(property: 'price_delta', type: 'number', format: 'float', example: 1.50),
                    new OA\Property(property: 'available', type: 'boolean', example: true),
                    new OA\Property(property: 'sort_order', type: 'integer', example: 10),
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
                description: 'Modifier option created successfully',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'Modifier option created successfully.'),
                        new OA\Property(
                            property: 'data',
                            properties: [
                                new OA\Property(property: 'modifier_option', ref: '#/components/schemas/ModifierOption'),
                            ],
                            type: 'object'
                        ),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'The user is not allowed to create modifier options'),
            new OA\Response(response: 404, description: 'Modifier group not found'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function store(StoreModifierOptionRequest $request, int $modifierGroup): JsonResponse
    {
        $organization = $this->activeOrganization();
        $modifierGroupModel = $this->modifierGroupQuery($organization)->findOrFail($modifierGroup);

        $this->authorize('create', [ModifierOption::class, $modifierGroupModel]);

        $modifierOption = $this->createModifierOptionAction->execute($modifierGroupModel, $request->validated());

        return response()->json([
            'message' => 'Modifier option created successfully.',
            'data' => [
                'modifier_option' => new ModifierOptionResource($modifierOption),
            ],
        ], 201);
    }

    /**
     * Show a modifier option of the active organization.
     */
    #[OA\Get(
        path: '/api/v1/modifier-options/{modifierOption}',
        operationId: 'modifierOptionsShow',
        summary: 'Get a modifier option of the active organization',
        security: [['sessionCookie' => []]],
        tags: ['Modifiers'],
        parameters: [
            new OA\Parameter(name: 'modifierOption', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'The modifier option',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'data',
                            properties: [
                                new OA\Property(property: 'modifier_option', ref: '#/components/schemas/ModifierOption'),
                            ],
                            type: 'object'
                        ),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'The user is not allowed to view this modifier option'),
            new OA\Response(response: 404, description: 'Modifier option not found'),
        ]
    )]
    public function show(int $modifierOption): JsonResponse
    {
        $organization = $this->activeOrganization();
        $modifierOptionModel = $this->modifierOptionQuery($organization)->findOrFail($modifierOption);

        $this->authorize('view', $modifierOptionModel);

        return response()->json([
            'data' => [
                'modifier_option' => new ModifierOptionResource($modifierOptionModel),
            ],
        ]);
    }

    /**
     * Update a modifier option of the active organization.
     */
    #[OA\Patch(
        path: '/api/v1/modifier-options/{modifierOption}',
        operationId: 'modifierOptionsUpdate',
        summary: 'Update a modifier option of the active organization',
        security: [['sessionCookie' => []]],
        tags: ['Modifiers'],
        parameters: [
            new OA\Parameter(name: 'modifierOption', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: false,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'internal_name', type: 'string', example: 'Bacon'),
                    new OA\Property(property: 'price_delta', type: 'number', format: 'float', example: 1.50),
                    new OA\Property(property: 'available', type: 'boolean', example: true),
                    new OA\Property(property: 'sort_order', type: 'integer', example: 10),
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
                description: 'Modifier option updated successfully',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'Modifier option updated successfully.'),
                        new OA\Property(
                            property: 'data',
                            properties: [
                                new OA\Property(property: 'modifier_option', ref: '#/components/schemas/ModifierOption'),
                            ],
                            type: 'object'
                        ),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'The user is not allowed to update this modifier option'),
            new OA\Response(response: 404, description: 'Modifier option not found'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function update(UpdateModifierOptionRequest $request, int $modifierOption): JsonResponse
    {
        $organization = $this->activeOrganization();
        $modifierOptionModel = $this->modifierOptionQuery($organization)->findOrFail($modifierOption);

        $this->authorize('update', $modifierOptionModel);

        $modifierOptionModel = $this->updateModifierOptionAction->execute($modifierOptionModel, $request->validated());

        return response()->json([
            'message' => 'Modifier option updated successfully.',
            'data' => [
                'modifier_option' => new ModifierOptionResource($modifierOptionModel),
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
     * Modifier groups scoped to the active organization, via restaurant product -> restaurant.
     */
    private function modifierGroupQuery(Organization $organization): Builder
    {
        return ModifierGroup::query()->whereHas('restaurantProduct.restaurant', function ($query) use ($organization) {
            $query->where('organization_id', $organization->id);
        });
    }

    /**
     * Modifier options scoped to the active organization, via modifier group -> restaurant product -> restaurant.
     */
    private function modifierOptionQuery(Organization $organization): Builder
    {
        return ModifierOption::query()
            ->whereHas('modifierGroup.restaurantProduct.restaurant', function ($query) use ($organization) {
                $query->where('organization_id', $organization->id);
            })
            ->with('translations');
    }
}
