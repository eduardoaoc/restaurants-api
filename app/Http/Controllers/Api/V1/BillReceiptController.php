<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Printing\BuildBillReceiptAction;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\PrintRecord;
use App\Models\TableSession;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Restaurants\RestaurantScope;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class BillReceiptController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly BuildBillReceiptAction $buildBillReceipt,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * Preview the bill receipt document for a table session. Available
     * before payment, mid-payment, fully paid, or after the session has
     * closed — a receipt is never gated by payment/session state. A GET:
     * read-only, no PrintRecord is created.
     */
    #[OA\Get(
        path: '/api/v1/table-sessions/{tableSession}/receipt',
        operationId: 'tableSessionsReceiptShow',
        summary: 'Preview the bill receipt document for a table session',
        security: [['sessionCookie' => []]],
        tags: ['Printing'],
        parameters: [new OA\Parameter(name: 'tableSession', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'The bill receipt document', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/BillReceipt')])),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'The user is not allowed to view this table session\'s receipt'),
            new OA\Response(response: 404, description: 'Table session not found'),
        ]
    )]
    public function show(Request $request, int $tableSession): JsonResponse
    {
        $session = $this->resolveSession($request, $tableSession);

        return response()->json(['data' => $this->buildBillReceipt->execute($session)]);
    }

    /**
     * Register a manual bill receipt print request and return the same
     * document. A POST: creates a PrintRecord (a side effect) — repeatable
     * for reprints.
     */
    #[OA\Post(
        path: '/api/v1/table-sessions/{tableSession}/receipt/print',
        operationId: 'tableSessionsReceiptPrint',
        summary: 'Record a manual bill receipt print request',
        security: [['sessionCookie' => []]],
        tags: ['Printing'],
        parameters: [new OA\Parameter(name: 'tableSession', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 201, description: 'Print request recorded', content: new OA\JsonContent(ref: '#/components/schemas/PrintRequestResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'The user is not allowed to print this table session\'s receipt'),
            new OA\Response(response: 404, description: 'Table session not found'),
        ]
    )]
    public function print(Request $request, int $tableSession): JsonResponse
    {
        $session = $this->resolveSession($request, $tableSession);
        $user = $request->user();

        $organization = $this->activeOrganization();

        $printRecord = PrintRecord::query()->create([
            'organization_id' => $organization->id,
            'restaurant_id' => $session->restaurant_id,
            'document_type' => PrintRecord::DOCUMENT_TYPE_BILL_RECEIPT,
            'order_id' => null,
            'table_session_id' => $session->id,
            'requested_by_user_id' => $user->id,
            'generated_at' => now(),
        ]);

        $this->auditLogger->log(
            organizationId: $organization->id,
            restaurantId: $session->restaurant_id,
            actorType: AuditLog::ACTOR_USER,
            actor: $user,
            event: AuditLog::EVENT_PRINT_RECORD_CREATED,
            resourceType: AuditLog::RESOURCE_PRINT_RECORD,
            resourceId: $printRecord->id,
            metadata: [
                'document_type' => PrintRecord::DOCUMENT_TYPE_BILL_RECEIPT,
                'order_id' => null,
                'table_session_id' => $session->id,
            ],
        );

        return response()->json([
            'data' => [
                'print_record_id' => $printRecord->id,
                'document' => $this->buildBillReceipt->execute($session),
            ],
        ], 201);
    }

    /**
     * Resolve and authorize a table session, shared by preview and print
     * — same authorization for both (see TableSessionPolicy::viewReceipt()).
     */
    private function resolveSession(Request $request, int $tableSessionId): TableSession
    {
        $organization = $this->activeOrganization();
        $user = $request->user();
        $session = $this->tableSessionQuery($organization, $user)->findOrFail($tableSessionId);

        $this->authorize('viewReceipt', $session);

        return $session;
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
