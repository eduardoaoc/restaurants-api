<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Catalog\CreateCategoryAction;
use App\Actions\Catalog\UpdateCategoryAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Category\StoreCategoryRequest;
use App\Http\Requests\Api\V1\Category\UpdateCategoryRequest;
use App\Http\Resources\Api\V1\CategoryResource;
use App\Models\Category;
use App\Models\Organization;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

class CategoryController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly CreateCategoryAction $createCategoryAction,
        private readonly UpdateCategoryAction $updateCategoryAction,
    ) {}

    /**
     * List the categories of a restaurant's menu.
     */
    #[OA\Get(
        path: '/api/v1/restaurants/{restaurant}/categories',
        operationId: 'categoriesIndex',
        summary: "List a restaurant's menu categories",
        security: [['sessionCookie' => []]],
        tags: ['Categories'],
        parameters: [
            new OA\Parameter(name: 'restaurant', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'List of categories',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'data',
                            properties: [
                                new OA\Property(
                                    property: 'categories',
                                    type: 'array',
                                    items: new OA\Items(ref: '#/components/schemas/Category')
                                ),
                            ],
                            type: 'object'
                        ),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'The user is not allowed to view categories'),
            new OA\Response(response: 404, description: 'Restaurant not found'),
        ]
    )]
    public function index(int $restaurant): JsonResponse
    {
        $organization = $this->activeOrganization();
        $restaurantModel = $organization->restaurants()->findOrFail($restaurant);

        $this->authorize('viewAny', [Category::class, $restaurantModel]);

        $menu = $restaurantModel->menu;
        $categories = $menu
            ? $menu->categories()->with('translations')->orderBy('sort_order')->get()
            : collect();

        return response()->json([
            'data' => [
                'categories' => CategoryResource::collection($categories),
            ],
        ]);
    }

    /**
     * Create a category under a restaurant's menu.
     */
    #[OA\Post(
        path: '/api/v1/restaurants/{restaurant}/categories',
        operationId: 'categoriesStore',
        summary: "Create a category under a restaurant's menu",
        security: [['sessionCookie' => []]],
        tags: ['Categories'],
        parameters: [
            new OA\Parameter(name: 'restaurant', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['slug', 'translations'],
                properties: [
                    new OA\Property(property: 'slug', type: 'string', example: 'starters'),
                    new OA\Property(property: 'sort_order', type: 'integer', example: 1),
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
                description: 'Category created successfully',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'Category created successfully.'),
                        new OA\Property(
                            property: 'data',
                            properties: [
                                new OA\Property(property: 'category', ref: '#/components/schemas/Category'),
                            ],
                            type: 'object'
                        ),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'The user is not allowed to create categories'),
            new OA\Response(response: 404, description: 'Restaurant not found'),
            new OA\Response(response: 422, description: 'Validation error, or the restaurant has no menu yet'),
        ]
    )]
    public function store(StoreCategoryRequest $request, int $restaurant): JsonResponse
    {
        $organization = $this->activeOrganization();
        $restaurantModel = $organization->restaurants()->findOrFail($restaurant);

        $this->authorize('create', [Category::class, $restaurantModel]);

        $menu = $restaurantModel->menu;

        if (! $menu) {
            throw ValidationException::withMessages([
                'menu' => 'This restaurant does not have a menu yet.',
            ]);
        }

        $category = $this->createCategoryAction->execute($menu, $request->validated());

        return response()->json([
            'message' => 'Category created successfully.',
            'data' => [
                'category' => new CategoryResource($category),
            ],
        ], 201);
    }

    /**
     * Show a category of the active organization.
     */
    #[OA\Get(
        path: '/api/v1/categories/{category}',
        operationId: 'categoriesShow',
        summary: 'Get a category of the active organization',
        security: [['sessionCookie' => []]],
        tags: ['Categories'],
        parameters: [
            new OA\Parameter(name: 'category', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'The category',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'data',
                            properties: [
                                new OA\Property(property: 'category', ref: '#/components/schemas/Category'),
                            ],
                            type: 'object'
                        ),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'The user is not allowed to view this category'),
            new OA\Response(response: 404, description: 'Category not found'),
        ]
    )]
    public function show(int $category): JsonResponse
    {
        $organization = $this->activeOrganization();
        $categoryModel = $this->categoryQuery($organization)->findOrFail($category);

        $this->authorize('view', $categoryModel);

        return response()->json([
            'data' => [
                'category' => new CategoryResource($categoryModel),
            ],
        ]);
    }

    /**
     * Update a category of the active organization.
     */
    #[OA\Patch(
        path: '/api/v1/categories/{category}',
        operationId: 'categoriesUpdate',
        summary: 'Update a category of the active organization',
        security: [['sessionCookie' => []]],
        tags: ['Categories'],
        parameters: [
            new OA\Parameter(name: 'category', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: false,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'slug', type: 'string', example: 'starters'),
                    new OA\Property(property: 'sort_order', type: 'integer', example: 1),
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
                description: 'Category updated successfully',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'Category updated successfully.'),
                        new OA\Property(
                            property: 'data',
                            properties: [
                                new OA\Property(property: 'category', ref: '#/components/schemas/Category'),
                            ],
                            type: 'object'
                        ),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'The user is not allowed to update this category'),
            new OA\Response(response: 404, description: 'Category not found'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function update(UpdateCategoryRequest $request, int $category): JsonResponse
    {
        $organization = $this->activeOrganization();
        $categoryModel = $this->categoryQuery($organization)->findOrFail($category);

        $this->authorize('update', $categoryModel);

        $categoryModel = $this->updateCategoryAction->execute($categoryModel, $request->validated());

        return response()->json([
            'message' => 'Category updated successfully.',
            'data' => [
                'category' => new CategoryResource($categoryModel),
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
     * Categories scoped to the active organization, via menu -> restaurant.
     */
    private function categoryQuery(Organization $organization): Builder
    {
        return Category::query()
            ->whereHas('menu.restaurant', function ($query) use ($organization) {
                $query->where('organization_id', $organization->id);
            })
            ->with('translations');
    }
}
