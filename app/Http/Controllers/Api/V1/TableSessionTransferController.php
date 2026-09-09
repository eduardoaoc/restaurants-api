<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Tables\TransferTableSessionAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\TableSessions\TransferTableSessionRequest;
use App\Http\Resources\Api\V1\TableSessionResource;
use App\Models\Organization;
use App\Models\Table;
use App\Models\TableSession;
use App\Models\User;
use App\Support\Restaurants\RestaurantScope;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

/**
 * Transfers an active TableSession to another Table of the SAME
 * Restaurant (Bloco 4). Deliberately its own controller — mirrors
 * TableSessionBillController/TableSessionWaiterController: one focused
 * controller per sub-action of a table session.
 *
 * The target table is deliberately looked up scoped to the ORIGIN
 * session's own restaurant_id — not the broader RestaurantScope-accessible
 * set: transfer is always same-restaurant, even for an organization-wide
 * requester who could otherwise reach other restaurants. Any target id
 * outside that one restaurant resolves as 404, exactly like any other
 * out-of-scope resource in this codebase (see the Bloco 4 report).
 */
class TableSessionTransferController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly TransferTableSessionAction $transferTableSessionAction,
    ) {}

    #[OA\Post(
        path: '/api/v1/table-sessions/{tableSession}/transfer',
        operationId: 'tableSessionsTransfer',
        summary: 'Transfer an active table session to another table of the same restaurant',
        security: [['sessionCookie' => []]],
        tags: ['Table Sessions'],
        parameters: [
            new OA\Parameter(name: 'tableSession', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['target_table_id'],
                properties: [
                    new OA\Property(property: 'target_table_id', type: 'integer', example: 42),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Table session transferred successfully',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'Table session transferred successfully.'),
                        new OA\Property(
                            property: 'data',
                            properties: [new OA\Property(property: 'session', ref: '#/components/schemas/TableSession')],
                            type: 'object'
                        ),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'The user is not allowed to transfer this table session'),
            new OA\Response(response: 404, description: 'Table session not found, or the target table is not found in this same restaurant'),
            new OA\Response(response: 409, description: 'The session is closed, the target is the same table, or the target already has an active session'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function transfer(TransferTableSessionRequest $request, int $tableSession): JsonResponse
    {
        $organization = $this->activeOrganization();
        $user = $request->user();
        $session = $this->tableSessionQuery($organization, $user)->findOrFail($tableSession);

        $this->authorize('transfer', $session);

        $targetTable = Table::query()
            ->where('restaurant_id', $session->restaurant_id)
            ->findOrFail($request->validated('target_table_id'));

        $session = $this->transferTableSessionAction->execute($session, $targetTable, $user);

        return response()->json([
            'message' => 'Table session transferred successfully.',
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
