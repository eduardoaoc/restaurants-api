<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\DailyOperation\OpenDailyOperationAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\DailyOperation\OpenDailyOperationRequest;
use App\Models\Organization;
use App\Models\Restaurant;
use App\Models\User;
use App\Support\DailyOperation\DailyOperationState;
use App\Support\Restaurants\RestaurantScope;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * The operational day: its state (every staff member of the restaurant)
 * and its opening (open_daily_operation). Closing is the existing Cierre
 * Diario (DayCloseController), which finishes the open day.
 */
class DailyOperationController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly DailyOperationState $state,
        private readonly OpenDailyOperationAction $openDailyOperation,
    ) {}

    #[OA\Get(
        path: '/api/v1/restaurants/{restaurant}/daily-operation',
        operationId: 'dailyOperationShow',
        summary: 'Operational day state (open/not_open); cash block only for open/close holders',
        security: [['sessionCookie' => []]],
        tags: ['Daily operation'],
        parameters: [new OA\Parameter(name: 'restaurant', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'State in data'), new OA\Response(response: 403, description: 'No access'), new OA\Response(response: 404, description: 'Not found')],
    )]
    public function show(Request $request, int $restaurant): JsonResponse
    {
        $user = $request->user();
        $restaurantModel = $this->restaurantQuery($this->activeOrganization(), $user)->findOrFail($restaurant);
        $this->authorize('viewDailyOperation', $restaurantModel);

        return response()->json(['data' => $this->state->build($restaurantModel, $this->can($user, $restaurantModel))])
            ->header('Cache-Control', 'no-store');
    }

    #[OA\Post(
        path: '/api/v1/restaurants/{restaurant}/daily-operations',
        operationId: 'dailyOperationOpen',
        summary: 'Open the operational day with its opening float (open_daily_operation). Idempotent by Idempotency-Key.',
        security: [['sessionCookie' => []]],
        tags: ['Daily operation'],
        parameters: [
            new OA\Parameter(name: 'restaurant', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string', maxLength: 100)),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['opening_float'], properties: [new OA\Property(property: 'opening_float', type: 'string', example: '150.00')])),
        responses: [
            new OA\Response(response: 201, description: 'Opened; state in data'),
            new OA\Response(response: 200, description: 'Idempotent replay'),
            new OA\Response(response: 403, description: 'Permission denied'),
            new OA\Response(response: 409, description: 'DAILY_OPERATION_ALREADY_OPEN | BUSINESS_DATE_ALREADY_CLOSED | IDEMPOTENCY_KEY_REUSED'),
            new OA\Response(response: 422, description: 'Invalid opening float'),
        ],
    )]
    public function store(OpenDailyOperationRequest $request, int $restaurant): JsonResponse
    {
        $user = $request->user();
        $restaurantModel = $this->restaurantQuery($this->activeOrganization(), $user)->findOrFail($restaurant);
        $this->authorize('openDay', $restaurantModel);

        $result = $this->openDailyOperation->execute($restaurantModel, $user, $request->validated());

        return response()->json(
            ['data' => $this->state->build($restaurantModel->refresh(), $this->can($user, $restaurantModel))],
            $result['replayed'] ? 200 : 201,
        );
    }

    /**
     * @return array{open: bool, close: bool}
     */
    private function can(User $user, Restaurant $restaurant): array
    {
        return ['open' => $user->can('openDay', $restaurant), 'close' => $user->can('closeDay', $restaurant)];
    }

    private function activeOrganization(): Organization
    {
        return Organization::query()->findOrFail($this->tenantContext->getOrganizationId());
    }

    private function restaurantQuery(Organization $organization, User $requester): Builder
    {
        $accessibleRestaurantIds = RestaurantScope::accessibleRestaurantIds($requester, $organization);

        return Restaurant::query()
            ->where('organization_id', $organization->id)
            ->when($accessibleRestaurantIds !== null, fn (Builder $query) => $query->whereIn('id', $accessibleRestaurantIds));
    }
}
