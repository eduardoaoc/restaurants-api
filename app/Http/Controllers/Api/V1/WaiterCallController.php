<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Tables\AcknowledgeWaiterCallAction;
use App\Actions\Tables\CallResponsibleWaiterAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\WaiterCallResource;
use App\Models\Organization;
use App\Models\TableSession;
use App\Models\User;
use App\Models\WaiterCall;
use App\Support\Restaurants\RestaurantScope;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * "Call responsible waiter" (Bloco 4) — an internal Manager/Owner ->
 * assigned-waiter escalation. See WaiterCall/the Bloco 4 report for why
 * this is its own minimal resource rather than a TableRequest.
 */
class WaiterCallController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly CallResponsibleWaiterAction $callResponsibleWaiterAction,
        private readonly AcknowledgeWaiterCallAction $acknowledgeWaiterCallAction,
    ) {}

    /**
     * Call the waiter currently responsible for a table session.
     */
    #[OA\Post(
        path: '/api/v1/table-sessions/{tableSession}/waiter-calls',
        operationId: 'waiterCallsStore',
        summary: 'Call the waiter currently responsible for a table session',
        security: [['sessionCookie' => []]],
        tags: ['Waiter Calls'],
        parameters: [
            new OA\Parameter(name: 'tableSession', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 201,
                description: 'Waiter called successfully',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'Waiter called successfully.'),
                        new OA\Property(
                            property: 'data',
                            properties: [new OA\Property(property: 'waiter_call', ref: '#/components/schemas/WaiterCall')],
                            type: 'object'
                        ),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'The user is not allowed to call the responsible waiter'),
            new OA\Response(response: 404, description: 'Table session not found'),
            new OA\Response(response: 409, description: 'The session is closed, has no eligible assigned waiter, or already has a pending call'),
        ]
    )]
    public function store(Request $request, int $tableSession): JsonResponse
    {
        $organization = $this->activeOrganization();
        $user = $request->user();
        $session = $this->tableSessionQuery($organization, $user)->findOrFail($tableSession);

        $this->authorize('create', [WaiterCall::class, $session]);

        $call = $this->callResponsibleWaiterAction->execute($session, $user);

        return response()->json([
            'message' => 'Waiter called successfully.',
            'data' => [
                'waiter_call' => new WaiterCallResource($call),
            ],
        ], 201);
    }

    /**
     * Acknowledge a pending waiter call.
     */
    #[OA\Post(
        path: '/api/v1/waiter-calls/{waiterCall}/acknowledge',
        operationId: 'waiterCallsAcknowledge',
        summary: 'Acknowledge a pending waiter call',
        security: [['sessionCookie' => []]],
        tags: ['Waiter Calls'],
        parameters: [
            new OA\Parameter(name: 'waiterCall', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Waiter call acknowledged successfully',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'Waiter call acknowledged successfully.'),
                        new OA\Property(
                            property: 'data',
                            properties: [new OA\Property(property: 'waiter_call', ref: '#/components/schemas/WaiterCall')],
                            type: 'object'
                        ),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'The user is not allowed to acknowledge this call'),
            new OA\Response(response: 404, description: 'Waiter call not found'),
            new OA\Response(response: 409, description: 'This call has already been acknowledged'),
        ]
    )]
    public function acknowledge(Request $request, int $waiterCall): JsonResponse
    {
        $organization = $this->activeOrganization();
        $user = $request->user();
        $call = $this->waiterCallQuery($organization, $user)->findOrFail($waiterCall);

        $this->authorize('acknowledge', $call);

        $call = $this->acknowledgeWaiterCallAction->execute($call, $user);

        return response()->json([
            'message' => 'Waiter call acknowledged successfully.',
            'data' => [
                'waiter_call' => new WaiterCallResource($call),
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
     * Table sessions scoped to the active organization AND to the
     * restaurants the acting user may operate on.
     */
    private function tableSessionQuery(Organization $organization, User $user): Builder
    {
        $query = TableSession::query()->whereHas('restaurant', fn ($q) => $q->where('organization_id', $organization->id));

        $restaurantIds = RestaurantScope::accessibleRestaurantIds($user, $organization);

        if ($restaurantIds !== null) {
            $query->whereIn('restaurant_id', $restaurantIds);
        }

        return $query;
    }

    /**
     * Waiter calls scoped to the active organization AND to the
     * restaurants the acting user may operate on.
     */
    private function waiterCallQuery(Organization $organization, User $user): Builder
    {
        $query = WaiterCall::query()->whereHas('restaurant', fn ($q) => $q->where('organization_id', $organization->id));

        $restaurantIds = RestaurantScope::accessibleRestaurantIds($user, $organization);

        if ($restaurantIds !== null) {
            $query->whereIn('restaurant_id', $restaurantIds);
        }

        return $query;
    }
}
