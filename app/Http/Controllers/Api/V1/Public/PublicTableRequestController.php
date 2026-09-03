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
     * authorization to act on it.
     */
    #[OA\Post(
        path: '/api/v1/public/tables/{publicToken}/requests/call-waiter',
        operationId: 'publicTableRequestsCallWaiter',
        summary: 'Call the waiter from the public QR surface',
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
            new OA\Response(response: 409, description: 'No active table session, a call_waiter request is already open for this session, or WAITER_CALL_DISABLED', content: new OA\JsonContent(ref: '#/components/schemas/PublicApiError')),
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
     * session — that's a later block.
     */
    #[OA\Post(
        path: '/api/v1/public/tables/{publicToken}/requests/bill',
        operationId: 'publicTableRequestsBill',
        summary: 'Request the bill from the public QR surface',
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
            new OA\Response(response: 409, description: 'No active table session, a request_bill request is already open for this session, or BILL_REQUEST_DISABLED', content: new OA\JsonContent(ref: '#/components/schemas/PublicApiError')),
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
