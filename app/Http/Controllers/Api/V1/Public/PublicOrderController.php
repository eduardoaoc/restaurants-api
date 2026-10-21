<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Actions\Orders\CreatePublicOrderAction;
use App\Actions\Public\ResolvePublicTableAction;
use App\Exceptions\Public\InvalidPublicLocaleException;
use App\Exceptions\Public\PublicVisitInvalidException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Public\StorePublicOrderRequest;
use App\Http\Resources\Api\V1\Public\PublicOrderResource;
use App\Http\Resources\Api\V1\Public\PublicOrderTrackingResource;
use App\Models\Order;
use App\Support\Locale\LocaleResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class PublicOrderController extends Controller
{
    public function __construct(
        private readonly CreatePublicOrderAction $createPublicOrder,
        private readonly ResolvePublicTableAction $resolvePublicTable,
    ) {}

    #[OA\Get(
        path: '/api/v1/public/tables/{publicToken}/orders',
        operationId: 'publicOrdersIndex',
        summary: 'Track QR orders of the pinned group visit; never staff orders',
        tags: ['Public'],
        parameters: [
            new OA\Parameter(name: 'publicToken', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'X-Table-Visit', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'before_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'data.orders: snapshot-only QR orders, newest id first, 20 per page; meta.next_before_id: next cursor or null'),
            new OA\Response(response: 409, description: 'PUBLIC_VISIT_INVALID'),
            new OA\Response(response: 404, description: 'QR not publicly servable'),
        ],
    )]
    public function index(Request $request, string $publicToken): JsonResponse
    {
        $visitToken = $request->header('X-Table-Visit');
        if (! is_string($visitToken) || $visitToken === '') {
            throw new PublicVisitInvalidException;
        }
        $table = $this->resolvePublicTable->execute($publicToken, $visitToken);
        $data = $request->validate(['before_id' => ['sometimes', 'integer', 'min:1']]);
        // The existing visit token identifies a shared session, not one person.
        // Keep operational staff orders outside this live public projection.
        $query = $table->activeSession->orders()
            ->where('restaurant_id', $table->restaurant_id)
            ->where('origin', Order::ORIGIN_CUSTOMER_QR);
        if (isset($data['before_id'])) {
            $query->where('id', '<', $data['before_id']);
        }
        $rows = $query->with('items.modifiers')->orderByDesc('id')->limit(21)->get();
        $orders = $rows->take(20)->values();

        return response()->json(['data' => ['orders' => PublicOrderTrackingResource::collection($orders)], 'meta' => [
            'next_before_id' => $rows->count() > 20 ? $orders->last()->id : null,
        ]])->header('Cache-Control', 'no-store');
    }

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
            new OA\Parameter(name: 'X-Table-Visit', in: 'header', required: true, description: 'Visit token returned by POST entry (session.feedback.token).', schema: new OA\Schema(type: 'string')),
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
                description: 'No active table session, a concurrent session close, a reused idempotency key, CUSTOMER_ORDERING_DISABLED, or TABLE_SESSION_BILL_REQUESTED (the customer already requested the bill for this session)',
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

        $result = $this->createPublicOrder->execute($publicToken, $request->validated(), $locale, (string) $request->header('X-Table-Visit'));

        return response()->json([
            'data' => new PublicOrderResource($result['order']),
        ], $result['replayed'] ? 200 : 201);
    }
}
