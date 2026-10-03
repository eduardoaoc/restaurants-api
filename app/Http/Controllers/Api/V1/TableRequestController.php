<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\TableRequests\TransitionTableRequestStatusAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\TableRequests\IndexTableRequestsRequest;
use App\Http\Resources\Api\V1\TableRequestResource;
use App\Models\Organization;
use App\Models\Table;
use App\Models\TableRequest;
use App\Models\User;
use App\Support\Restaurants\RestaurantScope;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

class TableRequestController extends Controller
{
    /**
     * Oldest first: this is an operational queue, not a paginated list.
     */
    private const REQUEST_LIMIT = 100;

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly TransitionTableRequestStatusAction $transitionTableRequestStatus,
    ) {}

    /**
     * List table requests visible to the active organization, scoped to
     * the restaurants the acting user may operate on (see RestaurantScope).
     */
    #[OA\Get(
        path: '/api/v1/table-requests',
        operationId: 'tableRequestsIndex',
        summary: 'List table requests',
        security: [['sessionCookie' => []]],
        tags: ['Table Requests'],
        parameters: [
            new OA\Parameter(name: 'restaurant_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', example: 'pending')),
            new OA\Parameter(name: 'type', in: 'query', required: false, schema: new OA\Schema(type: 'string', example: 'call_waiter')),
            new OA\Parameter(name: 'table_id', in: 'query', required: false, description: 'Only requests of this table (CARTA 8.2A). Must be a table of a restaurant the user can access — and of restaurant_id when both are sent — otherwise 422 (same response for unknown and out-of-scope tables).', schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'List of table requests',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'data',
                            properties: [new OA\Property(property: 'table_requests', type: 'array', items: new OA\Items(ref: '#/components/schemas/TableRequest'))],
                            type: 'object'
                        ),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'The user is not allowed to view table requests'),
            new OA\Response(response: 422, description: 'Invalid status/type filter, or a table_id outside the user\'s scope / not of restaurant_id'),
        ]
    )]
    public function index(IndexTableRequestsRequest $request): JsonResponse
    {
        $organization = $this->activeOrganization();
        $user = $request->user();

        $this->authorize('viewAny', [TableRequest::class, $organization]);

        $query = $this->tableRequestQuery($organization, $user);

        if ($request->filled('restaurant_id')) {
            $query->where('restaurant_id', (int) $request->validated('restaurant_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->validated('status'));
        }
        if ($request->filled('type')) {
            $query->where('type', $request->validated('type'));
        }
        if ($request->filled('table_id')) {
            $tableId = $this->scopedTableId($organization, $user, (int) $request->validated('table_id'), $request->validated('restaurant_id'));
            $query->where('table_id', $tableId);
        }

        $tableRequests = $query->with(['restaurant', 'table', 'acknowledgedBy', 'completedBy', 'cancelledBy'])
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit(self::REQUEST_LIMIT)
            ->get();

        return response()->json([
            'data' => ['table_requests' => TableRequestResource::collection($tableRequests)],
        ]);
    }

    /**
     * Show a single table request, scoped to the active organization and
     * the acting user's restaurant scope.
     */
    #[OA\Get(
        path: '/api/v1/table-requests/{tableRequest}',
        operationId: 'tableRequestsShow',
        summary: 'Get a table request',
        security: [['sessionCookie' => []]],
        tags: ['Table Requests'],
        parameters: [new OA\Parameter(name: 'tableRequest', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(
                response: 200,
                description: 'The table request',
                content: new OA\JsonContent(properties: [new OA\Property(property: 'data', properties: [new OA\Property(property: 'table_request', ref: '#/components/schemas/TableRequest')], type: 'object')])
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'The user is not allowed to view this table request'),
            new OA\Response(response: 404, description: 'Table request not found'),
        ]
    )]
    public function show(Request $request, int $tableRequest): JsonResponse
    {
        $organization = $this->activeOrganization();
        $user = $request->user();
        $tableRequestModel = $this->tableRequestQuery($organization, $user)
            ->with(['restaurant', 'table', 'acknowledgedBy', 'completedBy', 'cancelledBy'])
            ->findOrFail($tableRequest);

        $this->authorize('view', $tableRequestModel);

        return response()->json([
            'data' => ['table_request' => new TableRequestResource($tableRequestModel)],
        ]);
    }

    /**
     * Acknowledge a pending request: pending -> acknowledged.
     */
    #[OA\Post(
        path: '/api/v1/table-requests/{tableRequest}/acknowledge',
        operationId: 'tableRequestsAcknowledge',
        summary: 'Acknowledge a pending table request',
        security: [['sessionCookie' => []]],
        tags: ['Table Requests'],
        parameters: [new OA\Parameter(name: 'tableRequest', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Request acknowledged successfully', content: new OA\JsonContent(properties: [new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', properties: [new OA\Property(property: 'table_request', ref: '#/components/schemas/TableRequest')], type: 'object')])),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'The user is not allowed to acknowledge table requests'),
            new OA\Response(response: 404, description: 'Table request not found'),
            new OA\Response(response: 409, description: 'The request is not pending'),
        ]
    )]
    public function acknowledge(Request $request, int $tableRequest): JsonResponse
    {
        return $this->transition($request, $tableRequest, 'acknowledge', fn ($r, $u) => $this->transitionTableRequestStatus->acknowledge($r, $u), 'Table request acknowledged successfully.');
    }

    /**
     * Complete an acknowledged request: acknowledged -> completed.
     */
    #[OA\Post(
        path: '/api/v1/table-requests/{tableRequest}/complete',
        operationId: 'tableRequestsComplete',
        summary: 'Complete an acknowledged table request',
        security: [['sessionCookie' => []]],
        tags: ['Table Requests'],
        parameters: [new OA\Parameter(name: 'tableRequest', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(properties: [new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', properties: [new OA\Property(property: 'table_request', ref: '#/components/schemas/TableRequest')], type: 'object')])),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'The user is not allowed to complete table requests'),
            new OA\Response(response: 404, description: 'Table request not found'),
            new OA\Response(response: 409, description: 'The request is not acknowledged'),
        ]
    )]
    public function complete(Request $request, int $tableRequest): JsonResponse
    {
        return $this->transition($request, $tableRequest, 'complete', fn ($r, $u) => $this->transitionTableRequestStatus->complete($r, $u), 'Table request completed successfully.');
    }

    /**
     * Cancel a pending or acknowledged request.
     */
    #[OA\Post(
        path: '/api/v1/table-requests/{tableRequest}/cancel',
        operationId: 'tableRequestsCancel',
        summary: 'Cancel a pending or acknowledged table request',
        security: [['sessionCookie' => []]],
        tags: ['Table Requests'],
        parameters: [new OA\Parameter(name: 'tableRequest', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Request cancelled successfully', content: new OA\JsonContent(properties: [new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', properties: [new OA\Property(property: 'table_request', ref: '#/components/schemas/TableRequest')], type: 'object')])),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'The user is not allowed to cancel table requests'),
            new OA\Response(response: 404, description: 'Table request not found'),
            new OA\Response(response: 409, description: 'The request is already completed or cancelled'),
        ]
    )]
    public function cancel(Request $request, int $tableRequest): JsonResponse
    {
        return $this->transition($request, $tableRequest, 'cancel', fn ($r, $u) => $this->transitionTableRequestStatus->cancel($r, $u), 'Table request cancelled successfully.');
    }

    /**
     * Shared plumbing for the three lifecycle transition endpoints:
     * resolve within tenant + restaurant scope (404 if outside it),
     * authorize the specific ability (403 if in scope but unauthorized),
     * then run the transition (409 on an invalid state change).
     */
    private function transition(Request $request, int $tableRequestId, string $ability, callable $run, string $message): JsonResponse
    {
        $organization = $this->activeOrganization();
        $user = $request->user();
        $tableRequestModel = $this->tableRequestQuery($organization, $user)->findOrFail($tableRequestId);

        $this->authorize($ability, $tableRequestModel);

        $updated = $run($tableRequestModel, $user);

        return response()->json([
            'message' => $message,
            'data' => ['table_request' => new TableRequestResource($updated)],
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
     * The table_id filter must name a table of the active organization,
     * within the user's RestaurantScope and — when restaurant_id is also
     * sent — of that restaurant. Anything else (unknown id, another
     * tenant's table, a sibling restaurant's table) is the SAME 422, so
     * the filter can never be used to probe for foreign tables.
     */
    private function scopedTableId(Organization $organization, User $user, int $tableId, mixed $restaurantId): int
    {
        $restaurantIds = RestaurantScope::accessibleRestaurantIds($user, $organization);

        $exists = Table::query()
            ->whereKey($tableId)
            ->whereHas('restaurant', fn ($q) => $q->where('organization_id', $organization->id))
            ->when($restaurantIds !== null, fn (Builder $q) => $q->whereIn('restaurant_id', $restaurantIds))
            ->when($restaurantId !== null, fn (Builder $q) => $q->where('restaurant_id', (int) $restaurantId))
            ->exists();

        if (! $exists) {
            throw ValidationException::withMessages(['table_id' => 'The selected table id is invalid.']);
        }

        return $tableId;
    }

    /**
     * Table requests scoped to the active organization AND to the
     * restaurants the acting user may operate on. A request outside either
     * scope resolves as "not found" via findOrFail() — see RestaurantScope.
     */
    private function tableRequestQuery(Organization $organization, User $user): Builder
    {
        $query = TableRequest::query()->whereHas('restaurant', fn ($q) => $q->where('organization_id', $organization->id));

        $restaurantIds = RestaurantScope::accessibleRestaurantIds($user, $organization);

        if ($restaurantIds !== null) {
            $query->whereIn('restaurant_id', $restaurantIds);
        }

        return $query;
    }
}
