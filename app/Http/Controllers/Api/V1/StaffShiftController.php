<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Staff\EndStaffShiftAction;
use App\Actions\Staff\StartStaffShiftAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Staff\IndexStaffShiftsRequest;
use App\Http\Requests\Api\V1\Staff\StartStaffShiftRequest;
use App\Http\Resources\Api\V1\Staff\StaffShiftResource;
use App\Models\Organization;
use App\Models\Restaurant;
use App\Models\StaffShift;
use App\Models\User;
use App\Support\Restaurants\RestaurantScope;
use App\Support\Staff\StaffShiftPeriodResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * Staff Shift (Bloco 3) — the canonical source of "is this staff member
 * active at this Restaurant right now". Explicitly NOT a
 * timesheet/payroll surface: start/end only, no editable historical
 * times, no breaks — see the Bloco 3 report.
 */
class StaffShiftController extends Controller
{
    private const DEFAULT_PER_PAGE = 25;

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly StartStaffShiftAction $startStaffShiftAction,
        private readonly EndStaffShiftAction $endStaffShiftAction,
    ) {}

    /**
     * List staff shifts of one Restaurant, newest-started first, paginated.
     * `active=true` doubles as "who's working right now" — deliberately
     * not a separate endpoint (see the Bloco 3 report): one filtered,
     * paginated list, matching AuditLogController's own filter shape.
     */
    #[OA\Get(
        path: '/api/v1/restaurants/{restaurant}/staff-shifts',
        operationId: 'staffShiftsIndex',
        summary: 'List staff shifts of a restaurant',
        security: [['sessionCookie' => []]],
        tags: ['Staff Shifts'],
        parameters: [
            new OA\Parameter(name: 'restaurant', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'user_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'active', in: 'query', required: false, description: 'true = only currently active shifts', schema: new OA\Schema(type: 'boolean')),
            new OA\Parameter(name: 'from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 25)),
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 1)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'A page of staff shifts, newest started first',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'data',
                            properties: [new OA\Property(property: 'staff_shifts', type: 'array', items: new OA\Items(ref: '#/components/schemas/StaffShift'))],
                            type: 'object'
                        ),
                        new OA\Property(property: 'meta', ref: '#/components/schemas/StaffShiftPaginationMeta'),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'The user is not allowed to view staff shifts'),
            new OA\Response(response: 404, description: 'Restaurant not found or outside scope'),
            new OA\Response(response: 422, description: 'Invalid filter value or period'),
        ]
    )]
    public function index(IndexStaffShiftsRequest $request, int $restaurant): JsonResponse
    {
        $organization = $this->activeOrganization();
        $user = $request->user();
        $restaurantModel = $this->restaurantQuery($organization, $user)->findOrFail($restaurant);

        $this->authorize('viewAny', [StaffShift::class, $organization]);

        $query = StaffShift::query()->where('restaurant_id', $restaurantModel->id);

        if ($request->filled('user_id')) {
            $query->where('user_id', (int) $request->validated('user_id'));
        }

        if ($request->has('active')) {
            $request->boolean('active')
                ? $query->whereNull('ended_at')
                : $query->whereNotNull('ended_at');
        }

        $period = StaffShiftPeriodResolver::resolve($request->validated('from'), $request->validated('to'));

        if ($period !== null) {
            $query->where('started_at', '>=', $period['from'])->where('started_at', '<', $period['toExclusive']);
        }

        $perPage = (int) ($request->validated('per_page') ?? self::DEFAULT_PER_PAGE);

        $shifts = $query->with(['user', 'startedBy', 'endedBy'])
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->paginate($perPage);

        return response()->json([
            'data' => [
                'staff_shifts' => StaffShiftResource::collection($shifts->items()),
            ],
            'meta' => [
                'current_page' => $shifts->currentPage(),
                'per_page' => $shifts->perPage(),
                'total' => $shifts->total(),
                'last_page' => $shifts->lastPage(),
            ],
        ]);
    }

    /**
     * Start a new shift for a user at a Restaurant. Self start is allowed
     * (see StaffShiftPolicy) — a staff member may start their own shift
     * without any special permission, as long as they hold membership at
     * this exact Restaurant.
     */
    #[OA\Post(
        path: '/api/v1/restaurants/{restaurant}/staff-shifts',
        operationId: 'staffShiftsStart',
        summary: 'Start a staff shift at a restaurant',
        security: [['sessionCookie' => []]],
        tags: ['Staff Shifts'],
        parameters: [
            new OA\Parameter(name: 'restaurant', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
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
                response: 201,
                description: 'Shift started successfully',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'Shift started successfully.'),
                        new OA\Property(
                            property: 'data',
                            properties: [new OA\Property(property: 'staff_shift', ref: '#/components/schemas/StaffShift')],
                            type: 'object'
                        ),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'The user is not allowed to start a shift for this user'),
            new OA\Response(response: 404, description: 'Restaurant not found or outside scope'),
            new OA\Response(response: 409, description: 'This user already has an active shift at this restaurant'),
            new OA\Response(response: 422, description: 'Validation error, or the selected user is not eligible for a shift here'),
        ]
    )]
    public function store(StartStaffShiftRequest $request, int $restaurant): JsonResponse
    {
        $organization = $this->activeOrganization();
        $user = $request->user();
        $restaurantModel = $this->restaurantQuery($organization, $user)->findOrFail($restaurant);

        $candidate = User::query()->findOrFail($request->validated('user_id'));

        $this->authorize('start', [StaffShift::class, $candidate, $restaurantModel]);

        $shift = $this->startStaffShiftAction->execute($restaurantModel, $candidate, $user);

        return response()->json([
            'message' => 'Shift started successfully.',
            'data' => [
                'staff_shift' => new StaffShiftResource($shift),
            ],
        ], 201);
    }

    /**
     * End an active shift. Self end is allowed (see StaffShiftPolicy).
     */
    #[OA\Post(
        path: '/api/v1/staff-shifts/{staffShift}/end',
        operationId: 'staffShiftsEnd',
        summary: 'End a staff shift',
        security: [['sessionCookie' => []]],
        tags: ['Staff Shifts'],
        parameters: [
            new OA\Parameter(name: 'staffShift', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Shift ended successfully',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'Shift ended successfully.'),
                        new OA\Property(
                            property: 'data',
                            properties: [new OA\Property(property: 'staff_shift', ref: '#/components/schemas/StaffShift')],
                            type: 'object'
                        ),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'The user is not allowed to end this shift'),
            new OA\Response(response: 404, description: 'Staff shift not found'),
            new OA\Response(response: 409, description: 'This shift has already ended'),
        ]
    )]
    public function end(Request $request, int $staffShift): JsonResponse
    {
        $organization = $this->activeOrganization();
        $user = $request->user();
        $shift = $this->staffShiftQuery($organization, $user)->findOrFail($staffShift);

        $this->authorize('end', $shift);

        $shift = $this->endStaffShiftAction->execute($shift, $user);

        return response()->json([
            'message' => 'Shift ended successfully.',
            'data' => [
                'staff_shift' => new StaffShiftResource($shift),
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
     * Restaurants of the active organization reachable by the requester —
     * an out-of-scope restaurant resolves as 404 via findOrFail, before
     * the permission/eligibility checks ever run. Mirrors
     * StaffController::restaurantQuery().
     */
    private function restaurantQuery(Organization $organization, User $requester): Builder
    {
        $accessibleRestaurantIds = RestaurantScope::accessibleRestaurantIds($requester, $organization);

        return Restaurant::query()
            ->where('organization_id', $organization->id)
            ->when(
                $accessibleRestaurantIds !== null,
                fn (Builder $query) => $query->whereIn('id', $accessibleRestaurantIds),
            );
    }

    /**
     * Staff shifts scoped to the active organization AND to the
     * restaurants the acting user may operate on. A shift outside either
     * scope resolves as "not found" via findOrFail().
     */
    private function staffShiftQuery(Organization $organization, User $user): Builder
    {
        $query = StaffShift::query()->whereHas('restaurant', fn ($q) => $q->where('organization_id', $organization->id));

        $restaurantIds = RestaurantScope::accessibleRestaurantIds($user, $organization);

        if ($restaurantIds !== null) {
            $query->whereIn('restaurant_id', $restaurantIds);
        }

        return $query;
    }
}
