<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Platform\IndexPlatformOrganizationsRequest;
use App\Http\Requests\Api\V1\Platform\UpdatePlatformOrganizationPlanRequest;
use App\Http\Requests\Api\V1\Platform\UpdatePlatformOrganizationStatusRequest;
use App\Http\Resources\Api\V1\Platform\PlatformOrganizationResource;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Support\Audit\PlatformAuditLogger;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

class PlatformOrganizationController extends Controller
{
    private const DEFAULT_PER_PAGE = 25;

    public function __construct(private readonly PlatformAuditLogger $auditLogger) {}

    #[OA\Get(
        path: '/api/v1/platform/organizations',
        operationId: 'platformOrganizationsIndex',
        summary: 'List every organization on the platform',
        security: [['sessionCookie' => []]],
        tags: ['Platform Admin'],
        parameters: [
            new OA\Parameter(name: 'search', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', example: 'active')),
            new OA\Parameter(name: 'plan', in: 'query', required: false, schema: new OA\Schema(type: 'string', example: 'free')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 25)),
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'A page of organizations'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Platform administrator access required'),
        ]
    )]
    public function index(IndexPlatformOrganizationsRequest $request): JsonResponse
    {
        $this->authorize('platform.organizations.view');

        $query = Organization::query()->withCount(['restaurants', 'users']);

        if ($request->filled('search')) {
            $search = $request->validated('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%");
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->validated('status'));
        }

        if ($request->filled('plan')) {
            $query->where('plan', $request->validated('plan'));
        }

        $perPage = (int) ($request->validated('per_page') ?? self::DEFAULT_PER_PAGE);

        $organizations = $query->orderByDesc('id')->paginate($perPage);

        return response()->json([
            'data' => [
                'organizations' => PlatformOrganizationResource::collection($organizations->items()),
            ],
            'meta' => $this->paginationMeta($organizations),
        ]);
    }

    #[OA\Get(
        path: '/api/v1/platform/organizations/{organization}',
        operationId: 'platformOrganizationsShow',
        summary: 'Get one organization',
        security: [['sessionCookie' => []]],
        tags: ['Platform Admin'],
        parameters: [
            new OA\Parameter(name: 'organization', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The organization'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Platform administrator access required'),
            new OA\Response(response: 404, description: 'Organization not found'),
        ]
    )]
    public function show(int $organization): JsonResponse
    {
        $this->authorize('platform.organizations.view');

        $organizationModel = Organization::query()->withCount(['restaurants', 'users'])->findOrFail($organization);

        return response()->json([
            'data' => [
                'organization' => new PlatformOrganizationResource($organizationModel),
            ],
        ]);
    }

    /**
     * Suspending an Organization is enforced at ResolveTenant, not here —
     * every tenant route (including the owner's own PATCH /organization)
     * becomes unreachable while status is suspended. See ResolveTenant and
     * the Bloco 0 report.
     */
    #[OA\Patch(
        path: '/api/v1/platform/organizations/{organization}/status',
        operationId: 'platformOrganizationsUpdateStatus',
        summary: 'Suspend or reactivate an organization',
        security: [['sessionCookie' => []]],
        tags: ['Platform Admin'],
        parameters: [
            new OA\Parameter(name: 'organization', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['status', 'reason'],
                properties: [
                    new OA\Property(property: 'status', type: 'string', example: 'suspended'),
                    new OA\Property(property: 'reason', type: 'string', example: 'Payment failed for 3 consecutive cycles.'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Organization status updated successfully'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Platform administrator access required'),
            new OA\Response(response: 404, description: 'Organization not found'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function updateStatus(UpdatePlatformOrganizationStatusRequest $request, int $organization): JsonResponse
    {
        $this->authorize('platform.organizations.manage');

        $organizationModel = Organization::query()->findOrFail($organization);

        $oldStatus = $organizationModel->status;
        $newStatus = $request->validated('status');

        $organizationModel->update(['status' => $newStatus]);

        $this->auditLogger->log(
            actor: $request->user(),
            event: $newStatus === Organization::STATUS_SUSPENDED
                ? AuditLog::EVENT_PLATFORM_ORGANIZATION_SUSPENDED
                : AuditLog::EVENT_PLATFORM_ORGANIZATION_REACTIVATED,
            resourceType: AuditLog::RESOURCE_ORGANIZATION,
            resourceId: $organizationModel->id,
            organizationId: $organizationModel->id,
            restaurantId: null,
            reason: $request->validated('reason'),
            changes: ['status' => ['old' => $oldStatus, 'new' => $newStatus]],
        );

        return response()->json([
            'message' => 'Organization status updated successfully.',
            'data' => [
                'organization' => new PlatformOrganizationResource($organizationModel->fresh()),
            ],
        ]);
    }

    /**
     * Corrects/updates the Organization's SaaS plan and/or subscription
     * state administratively. This is deliberately not a billing
     * integration — see the Bloco 0 report's Plans section for what
     * exists (Organization.plan/subscription_status only) and what does
     * not (no invoices, no Stripe, no payment method).
     */
    #[OA\Patch(
        path: '/api/v1/platform/organizations/{organization}/plan',
        operationId: 'platformOrganizationsUpdatePlan',
        summary: "Correct an organization's plan and/or subscription status",
        security: [['sessionCookie' => []]],
        tags: ['Platform Admin'],
        parameters: [
            new OA\Parameter(name: 'organization', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['reason'],
                properties: [
                    new OA\Property(property: 'plan', type: 'string', example: 'pro'),
                    new OA\Property(property: 'subscription_status', type: 'string', example: 'active'),
                    new OA\Property(property: 'reason', type: 'string', example: 'Manually reinstated after support ticket #501.'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Organization plan updated successfully'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Platform administrator access required'),
            new OA\Response(response: 404, description: 'Organization not found'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function updatePlan(UpdatePlatformOrganizationPlanRequest $request, int $organization): JsonResponse
    {
        $this->authorize('platform.organizations.manage');

        $organizationModel = Organization::query()->findOrFail($organization);

        $changes = [];
        $data = [];

        if ($request->filled('plan') && $request->validated('plan') !== $organizationModel->plan) {
            $changes['plan'] = ['old' => $organizationModel->plan, 'new' => $request->validated('plan')];
            $data['plan'] = $request->validated('plan');
        }

        if ($request->filled('subscription_status') && $request->validated('subscription_status') !== $organizationModel->subscription_status) {
            $changes['subscription_status'] = ['old' => $organizationModel->subscription_status, 'new' => $request->validated('subscription_status')];
            $data['subscription_status'] = $request->validated('subscription_status');
        }

        if ($data !== []) {
            $organizationModel->update($data);

            $this->auditLogger->log(
                actor: $request->user(),
                event: AuditLog::EVENT_PLATFORM_ORGANIZATION_PLAN_CHANGED,
                resourceType: AuditLog::RESOURCE_ORGANIZATION,
                resourceId: $organizationModel->id,
                organizationId: $organizationModel->id,
                restaurantId: null,
                reason: $request->validated('reason'),
                changes: $changes,
            );
        }

        return response()->json([
            'message' => 'Organization plan updated successfully.',
            'data' => [
                'organization' => new PlatformOrganizationResource($organizationModel->fresh()),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function paginationMeta(LengthAwarePaginator $paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'last_page' => $paginator->lastPage(),
        ];
    }
}
