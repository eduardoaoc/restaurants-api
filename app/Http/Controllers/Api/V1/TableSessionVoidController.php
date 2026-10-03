<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Tables\VoidEmptyTableSessionAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\TableSessions\VoidTableSessionRequest;
use App\Http\Resources\Api\V1\TableSessionResource;
use App\Models\Organization;
use App\Models\TableSession;
use App\Models\User;
use App\Support\Restaurants\RestaurantScope;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

/**
 * Voids an EMPTY active table session (CARTA 9.1A) — see
 * VoidEmptyTableSessionAction. One focused controller per sub-action of a
 * table session, like TableSessionTransferController.
 */
class TableSessionVoidController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly VoidEmptyTableSessionAction $voidEmptyTableSession,
    ) {}

    #[OA\Post(
        path: '/api/v1/table-sessions/{tableSession}/void',
        operationId: 'tableSessionsVoid',
        summary: 'Void an empty active table session (no payments, no billable orders, no order in progress)',
        description: 'Ends a session opened by mistake or abandoned before ordering. The session is kept (status closed, voided_at set) but never counts as an attended session, a closed session, guests served or turnover. Still-open table requests are cancelled. Rejected orders are allowed.',
        security: [['sessionCookie' => []]],
        tags: ['Table Sessions'],
        parameters: [
            new OA\Parameter(name: 'tableSession', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: false,
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'reason', type: 'string', maxLength: 255, nullable: true, example: 'Mesa abierta por error'),
            ])
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Session voided',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'message', type: 'string', example: 'Table session voided successfully.'),
                    new OA\Property(property: 'data', properties: [new OA\Property(property: 'session', ref: '#/components/schemas/TableSession')], type: 'object'),
                ])
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Requires manage_tables or close_bill'),
            new OA\Response(response: 404, description: 'Table session not found in the user\'s organization/restaurant scope'),
            new OA\Response(response: 409, description: 'TABLE_SESSION_CLOSED, or TABLE_SESSION_NOT_EMPTY with reason has_payments | has_billable_orders | has_open_orders'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function void(VoidTableSessionRequest $request, int $tableSession): JsonResponse
    {
        $user = $request->user();
        $session = $this->tableSessionQuery($this->activeOrganization(), $user)->findOrFail($tableSession);

        $this->authorize('void', $session);

        $voided = $this->voidEmptyTableSession->execute($session, $user, $request->validated('reason'));

        return response()->json([
            'message' => 'Table session voided successfully.',
            'data' => ['session' => new TableSessionResource($voided)],
        ]);
    }

    private function activeOrganization(): Organization
    {
        return Organization::query()->findOrFail($this->tenantContext->getOrganizationId());
    }

    /**
     * Same scoping as every other table-session controller: an out-of-scope
     * session resolves as 404.
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
