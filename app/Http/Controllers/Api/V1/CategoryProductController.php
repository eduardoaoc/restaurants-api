<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CategoryProduct\AttachCategoryProductRequest;
use App\Http\Requests\Api\V1\CategoryProduct\UpdateCategoryProductRequest;
use App\Http\Resources\Api\V1\CategoryProductResource;
use App\Models\Category;
use App\Models\Organization;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

class CategoryProductController extends Controller
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    /**
     * Place a restaurant product into a category.
     */
    #[OA\Post(
        path: '/api/v1/categories/{category}/products',
        operationId: 'categoryProductsStore',
        summary: 'Place a restaurant product into a category',
        security: [['sessionCookie' => []]],
        tags: ['Categories'],
        parameters: [
            new OA\Parameter(name: 'category', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['restaurant_product_id'],
                properties: [
                    new OA\Property(property: 'restaurant_product_id', type: 'integer', example: 25),
                    new OA\Property(property: 'sort_order', type: 'integer', example: 10),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Product added to category successfully',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'Product added to category successfully.'),
                        new OA\Property(
                            property: 'data',
                            properties: [
                                new OA\Property(property: 'category_product', ref: '#/components/schemas/CategoryProduct'),
                            ],
                            type: 'object'
                        ),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'The user is not allowed to manage this category'),
            new OA\Response(response: 404, description: 'Category not found'),
            new OA\Response(response: 422, description: 'Validation error, product from another restaurant, or link already exists'),
        ]
    )]
    public function store(AttachCategoryProductRequest $request, int $category): JsonResponse
    {
        $organization = $this->activeOrganization();
        $categoryModel = $this->categoryQuery($organization)->findOrFail($category);

        $this->authorize('update', $categoryModel);

        $categoryProduct = $categoryModel->categoryProducts()->create($request->validated());

        return response()->json([
            'message' => 'Product added to category successfully.',
            'data' => [
                'category_product' => new CategoryProductResource($categoryProduct),
            ],
        ], 201);
    }

    /**
     * Update the sort order of a product within a category.
     */
    #[OA\Patch(
        path: '/api/v1/categories/{category}/products/{restaurantProduct}',
        operationId: 'categoryProductsUpdate',
        summary: 'Update the sort order of a product within a category',
        security: [['sessionCookie' => []]],
        tags: ['Categories'],
        parameters: [
            new OA\Parameter(name: 'category', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'restaurantProduct', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['sort_order'],
                properties: [
                    new OA\Property(property: 'sort_order', type: 'integer', example: 20),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Category product updated successfully',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'Category product updated successfully.'),
                        new OA\Property(
                            property: 'data',
                            properties: [
                                new OA\Property(property: 'category_product', ref: '#/components/schemas/CategoryProduct'),
                            ],
                            type: 'object'
                        ),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'The user is not allowed to manage this category'),
            new OA\Response(response: 404, description: 'Category or category product not found'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function update(UpdateCategoryProductRequest $request, int $category, int $restaurantProduct): JsonResponse
    {
        $organization = $this->activeOrganization();
        $categoryModel = $this->categoryQuery($organization)->findOrFail($category);

        $this->authorize('update', $categoryModel);

        $categoryProductModel = $categoryModel->categoryProducts()
            ->where('restaurant_product_id', $restaurantProduct)
            ->firstOrFail();

        $categoryProductModel->update($request->validated());

        return response()->json([
            'message' => 'Category product updated successfully.',
            'data' => [
                'category_product' => new CategoryProductResource($categoryProductModel),
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
        return Category::query()->whereHas('menu.restaurant', function ($query) use ($organization) {
            $query->where('organization_id', $organization->id);
        });
    }
}
