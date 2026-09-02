<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Catalog\AttachProductToRestaurantAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\RestaurantProduct\AttachRestaurantProductRequest;
use App\Http\Requests\Api\V1\RestaurantProduct\UpdateRestaurantProductRequest;
use App\Http\Resources\Api\V1\RestaurantProductResource;
use App\Models\Organization;
use App\Models\RestaurantProduct;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

class RestaurantProductController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly AttachProductToRestaurantAction $attachProductToRestaurantAction,
    ) {}

    /**
     * Add a catalog product to a restaurant, with its price and availability.
     */
    #[OA\Post(
        path: '/api/v1/restaurants/{restaurant}/products',
        operationId: 'restaurantProductsStore',
        summary: 'Add a catalog product to a restaurant',
        security: [['sessionCookie' => []]],
        tags: ['Restaurant Products'],
        parameters: [
            new OA\Parameter(name: 'restaurant', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['product_id', 'price'],
                properties: [
                    new OA\Property(property: 'product_id', type: 'integer', example: 15),
                    new OA\Property(property: 'price', type: 'number', format: 'float', example: 12.90),
                    new OA\Property(property: 'available', type: 'boolean', example: true),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Product added to restaurant successfully',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'Product added to restaurant successfully.'),
                        new OA\Property(
                            property: 'data',
                            properties: [
                                new OA\Property(property: 'restaurant_product', ref: '#/components/schemas/RestaurantProduct'),
                            ],
                            type: 'object'
                        ),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'The user is not allowed to manage restaurant products'),
            new OA\Response(response: 404, description: 'Restaurant not found'),
            new OA\Response(response: 422, description: 'Validation error, product from another organization, or link already exists'),
        ]
    )]
    public function store(AttachRestaurantProductRequest $request, int $restaurant): JsonResponse
    {
        $organization = $this->activeOrganization();
        $restaurantModel = $organization->restaurants()->findOrFail($restaurant);

        $this->authorize('create', [RestaurantProduct::class, $restaurantModel]);

        $product = $organization->products()->findOrFail($request->validated('product_id'));

        $restaurantProduct = $this->attachProductToRestaurantAction->execute(
            $restaurantModel,
            $product,
            $request->validated(),
        );

        return response()->json([
            'message' => 'Product added to restaurant successfully.',
            'data' => [
                'restaurant_product' => new RestaurantProductResource($restaurantProduct->load('product.translations')),
            ],
        ], 201);
    }

    /**
     * Update the price/availability of a restaurant product.
     */
    #[OA\Patch(
        path: '/api/v1/restaurant-products/{restaurantProduct}',
        operationId: 'restaurantProductsUpdate',
        summary: 'Update the price/availability of a restaurant product',
        security: [['sessionCookie' => []]],
        tags: ['Restaurant Products'],
        parameters: [
            new OA\Parameter(name: 'restaurantProduct', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: false,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'price', type: 'number', format: 'float', example: 12.90),
                    new OA\Property(property: 'available', type: 'boolean', example: false),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Restaurant product updated successfully',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'Restaurant product updated successfully.'),
                        new OA\Property(
                            property: 'data',
                            properties: [
                                new OA\Property(property: 'restaurant_product', ref: '#/components/schemas/RestaurantProduct'),
                            ],
                            type: 'object'
                        ),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'The user is not allowed to manage restaurant products'),
            new OA\Response(response: 404, description: 'Restaurant product not found'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function update(UpdateRestaurantProductRequest $request, int $restaurantProduct): JsonResponse
    {
        $organization = $this->activeOrganization();
        $restaurantProductModel = $this->restaurantProductQuery($organization)->findOrFail($restaurantProduct);

        $this->authorize('update', $restaurantProductModel);

        $restaurantProductModel->update($request->validated());

        return response()->json([
            'message' => 'Restaurant product updated successfully.',
            'data' => [
                'restaurant_product' => new RestaurantProductResource($restaurantProductModel->load('product.translations')),
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
}
