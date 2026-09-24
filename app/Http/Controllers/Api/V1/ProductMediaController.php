<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Catalog\DeleteProductMediaAction;
use App\Actions\Catalog\StoreOrReplaceProductMediaAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Product\StoreProductMediaRequest;
use App\Http\Resources\Api\V1\ProductMediaResource;
use App\Models\Organization;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use OpenApi\Attributes as OA;

class ProductMediaController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly StoreOrReplaceProductMediaAction $storeOrReplaceAction,
        private readonly DeleteProductMediaAction $deleteAction,
    ) {}

    /**
     * Upload a product's image or video, replacing whatever already
     * occupies that slot.
     */
    #[OA\Post(
        path: '/api/v1/products/{product}/media/{type}',
        operationId: 'productsMediaStore',
        summary: "Upload (or replace) a product's image or video",
        security: [['sessionCookie' => []]],
        tags: ['Products'],
        parameters: [
            new OA\Parameter(name: 'product', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'type', in: 'path', required: true, schema: new OA\Schema(type: 'string', enum: ['image', 'video'])),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(
                    required: ['file'],
                    properties: [
                        new OA\Property(
                            property: 'file',
                            description: 'image: image/jpeg, image/png or image/webp, max 10MB. video: video/mp4 or video/webm, max 50MB. The MIME type is content-sniffed, not trusted from the filename.',
                            type: 'string',
                            format: 'binary'
                        ),
                    ],
                    type: 'object'
                )
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Media uploaded successfully — replaces any previous media of the same type',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'data',
                            properties: [
                                new OA\Property(property: 'media', ref: '#/components/schemas/ProductMedia'),
                            ],
                            type: 'object'
                        ),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'The user is not allowed to manage this product'),
            new OA\Response(response: 404, description: 'Product not found, or {type} is not image|video'),
            new OA\Response(response: 422, description: 'Missing file, wrong MIME type, or file over the size limit'),
        ]
    )]
    public function store(StoreProductMediaRequest $request, int $product, string $type): JsonResponse
    {
        $organization = $this->activeOrganization();
        $productModel = $organization->products()->findOrFail($product);

        $this->authorize('update', $productModel);

        $media = $this->storeOrReplaceAction->execute($productModel, $type, $request->file('file'));

        return response()->json([
            'data' => [
                'media' => new ProductMediaResource($media),
            ],
        ]);
    }

    /**
     * Remove a product's image or video. Idempotent: deleting an already
     * empty slot still returns 204.
     */
    #[OA\Delete(
        path: '/api/v1/products/{product}/media/{type}',
        operationId: 'productsMediaDestroy',
        summary: "Remove a product's image or video",
        security: [['sessionCookie' => []]],
        tags: ['Products'],
        parameters: [
            new OA\Parameter(name: 'product', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'type', in: 'path', required: true, schema: new OA\Schema(type: 'string', enum: ['image', 'video'])),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Media removed (or the slot was already empty)'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'The user is not allowed to manage this product'),
            new OA\Response(response: 404, description: 'Product not found, or {type} is not image|video'),
        ]
    )]
    public function destroy(int $product, string $type): Response
    {
        $organization = $this->activeOrganization();
        $productModel = $organization->products()->findOrFail($product);

        $this->authorize('update', $productModel);

        $this->deleteAction->execute($productModel, $type);

        return response()->noContent();
    }

    /**
     * Resolve the active organization from the tenant context.
     */
    private function activeOrganization(): Organization
    {
        return Organization::query()->findOrFail($this->tenantContext->getOrganizationId());
    }
}
