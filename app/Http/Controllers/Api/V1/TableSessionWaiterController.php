<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Tables\AssignWaiterAction;
use App\Actions\Tables\UnassignWaiterAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\TableSessions\AssignWaiterRequest;
use App\Http\Resources\Api\V1\TableSessionResource;
use App\Models\Organization;
use App\Models\TableSession;
use App\Models\User;
use App\Support\Restaurants\RestaurantScope;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * Assigns/reassigns/unassigns the waiter responsible for a table session
 * (Bloco 2). Deliberately its own controller, not folded into
 * TableSessionController — mirrors TableSessionBillController/
 * BillReceiptController: one focused controller per sub-resource of a
 * table session.
 *
 * The assignment belongs to the TableSession, never to the Table — see
 * the migration and WaiterAssignmentEligibility. There is intentionally no
 * `PATCH /tables/{table}/waiter` endpoint: a frontend starting from the
 * table must resolve its active session first (the Table already exposes
 * activeSession) and call this endpoint, preserving domain and history.
 */
class TableSessionWaiterController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly AssignWaiterAction $assignWaiterAction,
        private readonly UnassignWaiterAction $unassignWaiterAction,
    ) {}

    /**
     * Assign (or reassign) the waiter responsible for a table session.
     * Assigning the same waiter already responsible is a harmless no-op.
     */
    #[OA\Put(
        path: '/api/v1/table-sessions/{tableSession}/waiter',
        operationId: 'tableSessionsAssignWaiter',
        summary: 'Assign or reassign the waiter responsible for a table session',
        security: [['sessionCookie' => []]],
        tags: ['Table Sessions'],
        parameters: [
            new OA\Parameter(name: 'tableSession', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['user_id'],
                properties: [
                    new OA\Property(property: 'user_id', type: 'integer', example: 12),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Waiter assigned successfully',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'Waiter assigned successfully.'),
                        new OA\Property(
                            property: 'data',
                            properties: [
                                new OA\Property(property: 'session', ref: '#/components/schemas/TableSession'),
                            ],
                            type: 'object'
                        ),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'The user is not allowed to assign waiters'),
            new OA\Response(response: 404, description: 'Table session not found'),
            new OA\Response(response: 409, description: 'This table session is closed'),
            new OA\Response(response: 422, description: 'Validation error, or the selected user is not an eligible waiter'),
        ]
    )]
    public function update(AssignWaiterRequest $request, int $tableSession): JsonResponse
    {
        $organization = $this->activeOrganization();
        $session = $this->tableSessionQuery($organization, $request->user())->findOrFail($tableSession);

        $this->authorize('assignWaiter', $session);

        $waiter = User::query()->findOrFail($request->validated('user_id'));

        $session = $this->assignWaiterAction->execute($session, $waiter, $request->user());

        return response()->json([
            'message' => 'Waiter assigned successfully.',
            'data' => [
                'session' => new TableSessionResource($session),
            ],
        ]);
    }

    /**
     * Unassign the waiter of a table session, leaving the session active
     * and unaffected otherwise (orders, requests, payments untouched).
     * Unassigning an already-unassigned session is a harmless no-op.
     */
    #[OA\Delete(
        path: '/api/v1/table-sessions/{tableSession}/waiter',
        operationId: 'tableSessionsUnassignWaiter',
        summary: 'Unassign the waiter of a table session',
        security: [['sessionCookie' => []]],
        tags: ['Table Sessions'],
        parameters: [
            new OA\Parameter(name: 'tableSession', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Waiter unassigned successfully',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'Waiter unassigned successfully.'),
                        new OA\Property(
                            property: 'data',
                            properties: [
                                new OA\Property(property: 'session', ref: '#/components/schemas/TableSession'),
                            ],
                            type: 'object'
                        ),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'The user is not allowed to unassign waiters'),
            new OA\Response(response: 404, description: 'Table session not found'),
            new OA\Response(response: 409, description: 'This table session is closed'),
        ]
    )]
    public function destroy(Request $request, int $tableSession): JsonResponse
    {
        $organization = $this->activeOrganization();
        $session = $this->tableSessionQuery($organization, $request->user())->findOrFail($tableSession);

        $this->authorize('assignWaiter', $session);

        $session = $this->unassignWaiterAction->execute($session, $request->user());

        return response()->json([
            'message' => 'Waiter unassigned successfully.',
            'data' => [
                'session' => new TableSessionResource($session),
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
     * restaurants the acting user may operate on. A session outside
     * either scope resolves as "not found" via findOrFail().
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
}
