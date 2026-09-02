<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Table\StoreTableRequest;
use App\Http\Requests\Api\V1\Table\UpdateTableRequest;
use App\Http\Resources\Api\V1\TableResource;
use App\Models\Organization;
use App\Models\Table;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

class TableController extends Controller
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    /**
     * List the tables of a restaurant belonging to the active organization.
     */
    #[OA\Get(
        path: '/api/v1/restaurants/{restaurant}/tables',
        operationId: 'tablesIndex',
        summary: 'List the tables of a restaurant',
        security: [['sessionCookie' => []]],
        tags: ['Tables'],
        parameters: [
            new OA\Parameter(name: 'restaurant', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'List of tables',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'data',
                            properties: [
                                new OA\Property(
                                    property: 'tables',
                                    type: 'array',
                                    items: new OA\Items(ref: '#/components/schemas/Table')
                                ),
                            ],
                            type: 'object'
                        ),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'The user is not allowed to view tables'),
            new OA\Response(response: 404, description: 'Restaurant not found'),
        ]
    )]
    public function index(int $restaurant): JsonResponse
    {
        $organization = $this->activeOrganization();
        $restaurantModel = $organization->restaurants()->findOrFail($restaurant);

        $this->authorize('viewAny', [Table::class, $restaurantModel]);

        $tables = $restaurantModel->tables()->with('activeSession')->get();

        return response()->json([
            'data' => [
                'tables' => TableResource::collection($tables),
            ],
        ]);
    }

    /**
     * Create a table under a restaurant belonging to the active organization.
     */
    #[OA\Post(
        path: '/api/v1/restaurants/{restaurant}/tables',
        operationId: 'tablesStore',
        summary: 'Create a table under a restaurant',
        security: [['sessionCookie' => []]],
        tags: ['Tables'],
        parameters: [
            new OA\Parameter(name: 'restaurant', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name'],
                properties: [
                    new OA\Property(property: 'name', type: 'string', example: 'Mesa 12'),
                    new OA\Property(property: 'number', type: 'integer', example: 12, nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Table created successfully',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'Table created successfully.'),
                        new OA\Property(
                            property: 'data',
                            properties: [
                                new OA\Property(property: 'table', ref: '#/components/schemas/Table'),
                            ],
                            type: 'object'
                        ),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'The user is not allowed to create tables'),
            new OA\Response(response: 404, description: 'Restaurant not found'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function store(StoreTableRequest $request, int $restaurant): JsonResponse
    {
        $organization = $this->activeOrganization();
        $restaurantModel = $organization->restaurants()->findOrFail($restaurant);

        $this->authorize('create', [Table::class, $restaurantModel]);

        $table = $restaurantModel->tables()->create([
            ...$request->validated(),
            'public_token' => Table::generateUniquePublicToken(),
            'status' => 'active',
        ]);

        return response()->json([
            'message' => 'Table created successfully.',
            'data' => [
                'table' => new TableResource($table),
            ],
        ], 201);
    }

    /**
     * Show a table belonging to the active organization.
     */
    #[OA\Get(
        path: '/api/v1/tables/{table}',
        operationId: 'tablesShow',
        summary: 'Get a table of the active organization',
        security: [['sessionCookie' => []]],
        tags: ['Tables'],
        parameters: [
            new OA\Parameter(name: 'table', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'The table',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'data',
                            properties: [
                                new OA\Property(property: 'table', ref: '#/components/schemas/Table'),
                            ],
                            type: 'object'
                        ),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'The user is not allowed to view this table'),
            new OA\Response(response: 404, description: 'Table not found'),
        ]
    )]
    public function show(int $table): JsonResponse
    {
        $organization = $this->activeOrganization();
        $tableModel = $this->tableQuery($organization)->findOrFail($table);

        $this->authorize('view', $tableModel);

        return response()->json([
            'data' => [
                'table' => new TableResource($tableModel),
            ],
        ]);
    }

    /**
     * Update a table belonging to the active organization.
     */
    #[OA\Patch(
        path: '/api/v1/tables/{table}',
        operationId: 'tablesUpdate',
        summary: 'Update a table of the active organization',
        security: [['sessionCookie' => []]],
        tags: ['Tables'],
        parameters: [
            new OA\Parameter(name: 'table', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: false,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'name', type: 'string', example: 'Mesa 12'),
                    new OA\Property(property: 'number', type: 'integer', example: 12, nullable: true),
                    new OA\Property(property: 'status', type: 'string', example: 'active'),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Table updated successfully',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'Table updated successfully.'),
                        new OA\Property(
                            property: 'data',
                            properties: [
                                new OA\Property(property: 'table', ref: '#/components/schemas/Table'),
                            ],
                            type: 'object'
                        ),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'The user is not allowed to update this table'),
            new OA\Response(response: 404, description: 'Table not found'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function update(UpdateTableRequest $request, int $table): JsonResponse
    {
        $organization = $this->activeOrganization();
        $tableModel = $this->tableQuery($organization)->findOrFail($table);

        $this->authorize('update', $tableModel);

        $tableModel->update($request->validated());

        return response()->json([
            'message' => 'Table updated successfully.',
            'data' => [
                'table' => new TableResource($tableModel),
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
     * Tables scoped to the active organization, via their restaurant.
     */
    private function tableQuery(Organization $organization): Builder
    {
        return Table::query()
            ->whereHas('restaurant', function ($query) use ($organization) {
                $query->where('organization_id', $organization->id);
            })
            ->with('activeSession');
    }
}
