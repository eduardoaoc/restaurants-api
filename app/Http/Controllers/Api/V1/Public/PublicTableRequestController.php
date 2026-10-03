<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Actions\TableRequests\CreatePublicTableRequestAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Public\StorePublicTableRequestRequest;
use App\Http\Resources\Api\V1\Public\PublicTableRequestResource;
use App\Models\TableRequest;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

class PublicTableRequestController extends Controller
{
    public function __construct(private readonly CreatePublicTableRequestAction $createPublicTableRequest) {}

    /**
     * Call the waiter over. Requires an active table session, exactly like
     * order creation — the QR identifies the table, not a standing
     * authorization to act on it. Unlike order creation and request_bill,
     * still allowed once that session is paid (CARTA 5.1C).
     */
    #[OA\Post(
        path: '/api/v1/public/tables/{publicToken}/requests/call-waiter',
        operationId: 'publicTableRequestsCallWaiter',
        summary: 'Call the waiter from the public QR surface',
        description: 'Requires an active table session. A session that is already paid but still active may still call the waiter.',
        tags: ['Public'],
        parameters: [
            new OA\Parameter(name: 'publicToken', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: false,
            content: new OA\JsonContent(
                properties: [new OA\Property(property: 'note', type: 'string', example: 'Necesitamos ayuda con el menú', nullable: true)]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Request created', content: new OA\JsonContent(ref: '#/components/schemas/PublicTableRequest')),
            new OA\Response(response: 404, description: 'Table not found or not publicly servable', content: new OA\JsonContent(ref: '#/components/schemas/PublicApiError')),
            new OA\Response(response: 409, description: 'TABLE_SESSION_NOT_ACTIVE (no active table session), TABLE_REQUEST_ALREADY_OPEN (a call_waiter request is already open for this session), or WAITER_CALL_DISABLED', content: new OA\JsonContent(ref: '#/components/schemas/PublicApiError')),
            new OA\Response(response: 422, description: 'Malformed request', content: new OA\JsonContent(ref: '#/components/schemas/PublicApiError')),
            new OA\Response(response: 429, description: 'Too many requests', content: new OA\JsonContent(ref: '#/components/schemas/PublicApiError')),
        ]
    )]
    public function callWaiter(StorePublicTableRequestRequest $request, string $publicToken): JsonResponse
    {
        return $this->store($request, $publicToken, TableRequest::TYPE_CALL_WAITER);
    }

    /**
     * Ask for the bill. Does not create a payment or close the table
     * session — that's a later block. Only accepted once the service is
     * done: at least one billable order and none still open.
     */
    #[OA\Post(
        path: '/api/v1/public/tables/{publicToken}/requests/bill',
        operationId: 'publicTableRequestsBill',
        summary: 'Request the bill from the public QR surface',
        description: 'Requires an active, unpaid table session with at least one billable order and no order still waiting for approval or in the kitchen/delivery.',
        tags: ['Public'],
        parameters: [
            new OA\Parameter(name: 'publicToken', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: false,
            content: new OA\JsonContent(
                properties: [new OA\Property(property: 'note', type: 'string', nullable: true)]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Request created', content: new OA\JsonContent(ref: '#/components/schemas/PublicTableRequest')),
            new OA\Response(response: 404, description: 'Table not found or not publicly servable', content: new OA\JsonContent(ref: '#/components/schemas/PublicApiError')),
            new OA\Response(response: 409, description: 'TABLE_SESSION_NOT_ACTIVE (no active table session), TABLE_SESSION_ALREADY_PAID (the session is already fully paid), TABLE_SESSION_HAS_OPEN_ORDERS (an order is still waiting for approval or in the kitchen/delivery), TABLE_SESSION_HAS_NO_BILLABLE_ORDERS (nothing billable was consumed yet), TABLE_REQUEST_ALREADY_OPEN (a request_bill request is already open for this session), or BILL_REQUEST_DISABLED', content: new OA\JsonContent(ref: '#/components/schemas/PublicApiError')),
            new OA\Response(response: 422, description: 'Malformed request', content: new OA\JsonContent(ref: '#/components/schemas/PublicApiError')),
            new OA\Response(response: 429, description: 'Too many requests', content: new OA\JsonContent(ref: '#/components/schemas/PublicApiError')),
        ]
    )]
    public function bill(StorePublicTableRequestRequest $request, string $publicToken): JsonResponse
    {
        return $this->store($request, $publicToken, TableRequest::TYPE_REQUEST_BILL);
    }

    private function store(StorePublicTableRequestRequest $request, string $publicToken, string $type): JsonResponse
    {
        $tableRequest = $this->createPublicTableRequest->execute($publicToken, $type, $request->validated('note'));

        return response()->json([
            'data' => new PublicTableRequestResource($tableRequest),
        ], 201);
    }
}
