<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Catalog\CreateProductAction;
use App\Actions\Catalog\UpdateProductAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Product\StoreProductRequest;
use App\Http\Requests\Api\V1\Product\UpdateProductRequest;
use App\Http\Resources\Api\V1\ProductResource;
use App\Models\Organization;
use App\Models\Product;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

class ProductController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly CreateProductAction $createProductAction,
        private readonly UpdateProductAction $updateProductAction,
    ) {}

    /**
     * List the product catalog of the active organization.
     */
    #[OA\Get(
        path: '/api/v1/products',
        operationId: 'productsIndex',
        summary: 'List the product catalog of the active organization',
        security: [['sessionCookie' => []]],
        tags: ['Products'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'List of products',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'data',
                            properties: [
                                new OA\Property(
                                    property: 'products',
                                    type: 'array',
                                    items: new OA\Items(ref: '#/components/schemas/Product')
                                ),
                            ],
                            type: 'object'
                        ),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'The user is not allowed to view products'),
        ]
    )]
    public function index(): JsonResponse
    {
        $organization = $this->activeOrganization();

        $this->authorize('viewAny', [Product::class, $organization]);

        $products = $organization->products()->with('translations')->get();

        return response()->json([
            'data' => [
                'products' => ProductResource::collection($products),
            ],
        ]);
    }

    /**
     * Create a product in the catalog of the active organization.
     */
    #[OA\Post(
        path: '/api/v1/products',
        operationId: 'productsStore',
        summary: 'Create a product in the catalog of the active organization',
        security: [['sessionCookie' => []]],
        tags: ['Products'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['internal_name', 'translations'],
                properties: [
                    new OA\Property(property: 'sku', type: 'string', example: 'SKU-0001', nullable: true),
                    new OA\Property(property: 'internal_name', type: 'string', example: 'Coca-Cola 330ml'),
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
                description: 'Product created successfully',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'Product created successfully.'),
                        new OA\Property(
                            property: 'data',
                            properties: [
                                new OA\Property(property: 'product', ref: '#/components/schemas/Product'),
                            ],
                            type: 'object'
                        ),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'The user is not allowed to create products'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function store(StoreProductRequest $request): JsonResponse
    {
        $organization = $this->activeOrganization();

        $this->authorize('create', [Product::class, $organization]);

        $product = $this->createProductAction->execute($organization, $request->validated());

        return response()->json([
            'message' => 'Product created successfully.',
            'data' => [
                'product' => new ProductResource($product),
            ],
        ], 201);
    }

    /**
     * Show a product of the active organization's catalog.
     */
    #[OA\Get(
        path: '/api/v1/products/{product}',
        operationId: 'productsShow',
        summary: "Get a product of the active organization's catalog",
        security: [['sessionCookie' => []]],
        tags: ['Products'],
        parameters: [
            new OA\Parameter(name: 'product', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'The product',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'data',
                            properties: [
                                new OA\Property(property: 'product', ref: '#/components/schemas/Product'),
                            ],
                            type: 'object'
                        ),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'The user is not allowed to view this product'),
            new OA\Response(response: 404, description: 'Product not found'),
        ]
    )]
    public function show(int $product): JsonResponse
    {
        $organization = $this->activeOrganization();
        $productModel = $organization->products()->with('translations')->findOrFail($product);

        $this->authorize('view', $productModel);

        return response()->json([
            'data' => [
                'product' => new ProductResource($productModel),
            ],
        ]);
    }

    /**
     * Update a product of the active organization's catalog.
     */
    #[OA\Patch(
        path: '/api/v1/products/{product}',
        operationId: 'productsUpdate',
        summary: "Update a product of the active organization's catalog",
        security: [['sessionCookie' => []]],
        tags: ['Products'],
        parameters: [
            new OA\Parameter(name: 'product', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: false,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'sku', type: 'string', example: 'SKU-0001', nullable: true),
                    new OA\Property(property: 'internal_name', type: 'string', example: 'Coca-Cola 330ml'),
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
                description: 'Product updated successfully',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'Product updated successfully.'),
                        new OA\Property(
                            property: 'data',
                            properties: [
                                new OA\Property(property: 'product', ref: '#/components/schemas/Product'),
                            ],
                            type: 'object'
                        ),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'The user is not allowed to update this product'),
            new OA\Response(response: 404, description: 'Product not found'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function update(UpdateProductRequest $request, int $product): JsonResponse
    {
        $organization = $this->activeOrganization();
        $productModel = $organization->products()->findOrFail($product);

        $this->authorize('update', $productModel);

        $productModel = $this->updateProductAction->execute($productModel, $request->validated());

        return response()->json([
            'message' => 'Product updated successfully.',
            'data' => [
                'product' => new ProductResource($productModel),
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
