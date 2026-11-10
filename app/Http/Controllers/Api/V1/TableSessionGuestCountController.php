<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Tables\UpdateTableSessionGuestCountAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\TableSessions\UpdateGuestCountRequest;
use App\Http\Resources\Api\V1\TableSessionResource;
use App\Models\Organization;
use App\Models\TableSession;
use App\Models\User;
use App\Support\Restaurants\RestaurantScope;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

class TableSessionGuestCountController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly UpdateTableSessionGuestCountAction $updateAction,
    ) {}

    #[OA\Patch(
        path: '/api/v1/table-sessions/{tableSession}/guest-count',
        operationId: 'tableSessionsUpdateGuestCount',
        summary: 'Update the confirmed guest count of an active table session',
        security: [['sessionCookie' => []]],
        tags: ['Table Sessions'],
        parameters: [new OA\Parameter(name: 'tableSession', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['guest_count', 'expected_version'],
            properties: [
                new OA\Property(property: 'guest_count', type: 'integer', minimum: 1, maximum: 100),
                new OA\Property(property: 'expected_version', type: 'integer', minimum: 1, description: 'Version read with the session; stale versions return 409.'),
            ],
        )),
        responses: [
            new OA\Response(response: 200, description: 'Guest count updated or unchanged'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Requires manage_tables in the session restaurant'),
            new OA\Response(response: 404, description: 'Session not found in active tenant or restaurant scope'),
            new OA\Response(response: 409, description: 'Session is closed or expected_version is stale'),
            new OA\Response(response: 422, description: 'Invalid count or count exceeds table capacity'),
        ],
    )]
    public function update(UpdateGuestCountRequest $request, int $tableSession): JsonResponse
    {
        $organization = Organization::query()->findOrFail($this->tenantContext->getOrganizationId());
        $session = $this->tableSessionQuery($organization, $request->user())->findOrFail($tableSession);
        $this->authorize('updateGuestCount', $session);

        $updated = $this->updateAction->execute(
            $session,
            (int) $request->validated('guest_count'),
            (int) $request->validated('expected_version'),
        );

        return response()->json([
            'message' => 'Guest count updated successfully.',
            'data' => ['session' => new TableSessionResource($updated)],
        ]);
    }

    private function tableSessionQuery(Organization $organization, User $user): Builder
    {
        $query = TableSession::query()->whereHas('restaurant', fn ($q) => $q->where('organization_id', $organization->id));
        $restaurantIds = RestaurantScope::accessibleRestaurantIds($user, $organization);
        if ($restaurantIds !== null) $query->whereIn('restaurant_id', $restaurantIds);
        return $query;
    }
}
