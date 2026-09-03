<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Actions\Orders\CreatePublicOrderAction;
use App\Actions\Public\ResolvePublicTableAction;
use App\Exceptions\Public\InvalidPublicLocaleException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Public\StorePublicOrderRequest;
use App\Http\Resources\Api\V1\Public\PublicOrderResource;
use App\Support\Locale\LocaleResolver;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

class PublicOrderController extends Controller
{
    public function __construct(
        private readonly CreatePublicOrderAction $createPublicOrder,
        private readonly ResolvePublicTableAction $resolvePublicTable,
    ) {}

    /**
     * Create an order from the public QR surface. Requires an active table
     * session (unlike the menu, which is servable without one). The
     * frontend only sends intent (which RestaurantProduct, quantity,
     * modifier selections, notes) — price/totals/status/origin are always
     * computed/assigned by the backend.
     */
    #[OA\Post(
        path: '/api/v1/public/tables/{publicToken}/orders',
        operationId: 'publicOrdersStore',
        summary: 'Create an order from the public QR surface',
        tags: ['Public'],
        parameters: [
            new OA\Parameter(name: 'publicToken', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: false, schema: new OA\Schema(type: 'string', maxLength: 100)),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/PublicOrderCreateRequest')
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Order created',
                content: new OA\JsonContent(ref: '#/components/schemas/PublicOrderCreated')
            ),
            new OA\Response(
                response: 200,
                description: 'Idempotent replay of a previously created order',
                content: new OA\JsonContent(ref: '#/components/schemas/PublicOrderCreated')
            ),
            new OA\Response(
                response: 404,
                description: 'Table not found or not publicly servable',
                content: new OA\JsonContent(ref: '#/components/schemas/PublicApiError')
            ),
            new OA\Response(
                response: 409,
                description: 'No active table session, a concurrent session close, a reused idempotency key, or CUSTOMER_ORDERING_DISABLED',
                content: new OA\JsonContent(ref: '#/components/schemas/PublicApiError')
            ),
            new OA\Response(
                response: 422,
                description: 'Invalid item/modifier selection or malformed request',
                content: new OA\JsonContent(ref: '#/components/schemas/PublicApiError')
            ),
            new OA\Response(
                response: 429,
                description: 'Too many requests',
                content: new OA\JsonContent(ref: '#/components/schemas/PublicApiError')
            ),
        ]
    )]
    public function store(StorePublicOrderRequest $request, string $publicToken): JsonResponse
    {
        // Same allowlist rule as PublicMenuController::show(): only an
        // EXPLICITLY requested locale is checked against enabled_locales,
        // never the implicit/default resolution below.
        if ($request->validated('locale') !== null) {
            $table = $this->resolvePublicTable->execute($publicToken);
            $requested = LocaleResolver::normalize($request->validated('locale'));

            if (! in_array($requested, $table->restaurant->settings->enabled_locales, true)) {
                throw new InvalidPublicLocaleException;
            }
        }

        $locale = LocaleResolver::resolve($request->validated('locale'), $request->header('Accept-Language'));

        $result = $this->createPublicOrder->execute($publicToken, $request->validated(), $locale);

        return response()->json([
            'data' => new PublicOrderResource($result['order']),
        ], $result['replayed'] ? 200 : 201);
    }
}
