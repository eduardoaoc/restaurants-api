<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Tables\CloseTableAction;
use App\Actions\Tables\OpenTableAction;
use App\Exceptions\TableSessionConflictException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Table\OpenTableRequest;
use App\Http\Resources\Api\V1\TableSessionResource;
use App\Models\Organization;
use App\Models\Table;
use App\Models\User;
use App\Support\Restaurants\RestaurantScope;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class TableSessionController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly OpenTableAction $openTableAction,
        private readonly CloseTableAction $closeTableAction,
    ) {}

    /**
     * Open a new session for a table of the active organization.
     */
    #[OA\Post(
        path: '/api/v1/tables/{table}/open',
        operationId: 'tablesOpen',
        summary: 'Open a new session for a table',
        security: [['sessionCookie' => []]],
        tags: ['Tables'],
        parameters: [
            new OA\Parameter(name: 'table', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['guest_count'],
                properties: [
                    new OA\Property(property: 'guest_count', type: 'integer', example: 4),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Table opened successfully',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'Table opened successfully.'),
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
            new OA\Response(response: 403, description: 'The user is not allowed to open this table'),
            new OA\Response(response: 404, description: 'Table not found'),
            new OA\Response(response: 409, description: 'The table already has an active session'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function open(OpenTableRequest $request, int $table): JsonResponse
    {
        $organization = $this->activeOrganization();
        $tableModel = $this->tableQuery($organization, $request->user())->findOrFail($table);

        $this->authorize('open', $tableModel);

        $session = $this->openTableAction->execute(
            $tableModel,
            $request->user(),
            (int) $request->validated('guest_count'),
        );

        return response()->json([
            'message' => 'Table opened successfully.',
            'data' => [
                'session' => new TableSessionResource($session),
            ],
        ], 201);
    }

    /**
     * Close the active session of a table of the active organization.
     */
    #[OA\Post(
        path: '/api/v1/tables/{table}/close',
        operationId: 'tablesClose',
        summary: 'Close the active session of a table',
        security: [['sessionCookie' => []]],
        tags: ['Tables'],
        parameters: [
            new OA\Parameter(name: 'table', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Table closed successfully',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'Table closed successfully.'),
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
            new OA\Response(response: 403, description: 'The user is not allowed to close this table'),
            new OA\Response(response: 404, description: 'Table not found'),
            new OA\Response(response: 409, description: 'This table has no active session'),
        ]
    )]
    public function close(Request $request, int $table): JsonResponse
    {
        $organization = $this->activeOrganization();
        $tableModel = $this->tableQuery($organization, $request->user())->findOrFail($table);

        $activeSession = $tableModel->activeSession;

        if (! $activeSession) {
            throw new TableSessionConflictException('This table has no active session to close.');
        }

        $this->authorize('close', $activeSession);

        $session = $this->closeTableAction->execute($activeSession, $request->user());

        return response()->json([
            'message' => 'Table closed successfully.',
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
     * Tables scoped to the active organization AND to the restaurants the
     * acting user may operate on (see RestaurantScope). A table outside
     * either scope resolves as "not found" via findOrFail().
     */
    private function tableQuery(Organization $organization, User $user): Builder
    {
        $query = Table::query()->whereHas('restaurant', function ($query) use ($organization) {
            $query->where('organization_id', $organization->id);
        });

        $restaurantIds = RestaurantScope::accessibleRestaurantIds($user, $organization);

        if ($restaurantIds !== null) {
            $query->whereIn('restaurant_id', $restaurantIds);
        }

        return $query;
    }
}
