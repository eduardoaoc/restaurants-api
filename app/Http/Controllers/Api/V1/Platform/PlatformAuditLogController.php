<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Platform\IndexPlatformAuditLogRequest;
use App\Http\Resources\Api\V1\AuditLogResource;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

/**
 * Read-only view of platform-level audit events — reuses the same
 * AuditLog table/AuditLogResource as the tenant audit log
 * (AuditLogController), filtered to actor_type = platform_admin. See the
 * Bloco 0 report for why a second table would have been duplication.
 */
class PlatformAuditLogController extends Controller
{
    private const DEFAULT_PER_PAGE = 25;

    #[OA\Get(
        path: '/api/v1/platform/audit-logs',
        operationId: 'platformAuditLogsIndex',
        summary: 'List platform-level audit events',
        security: [['sessionCookie' => []]],
        tags: ['Platform Admin'],
        parameters: [
            new OA\Parameter(name: 'event', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'resource_type', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'resource_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'actor_user_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 25)),
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'A page of platform audit events, newest first'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Platform administrator access required'),
        ]
    )]
    public function index(IndexPlatformAuditLogRequest $request): JsonResponse
    {
        $this->authorize('platform.audit.view');

        $query = AuditLog::query()->where('actor_type', AuditLog::ACTOR_PLATFORM_ADMIN);

        if ($request->filled('event')) {
            $query->where('event', $request->validated('event'));
        }

        if ($request->filled('resource_type')) {
            $query->where('resource_type', $request->validated('resource_type'));
        }

        if ($request->filled('resource_id')) {
            $query->where('resource_id', (int) $request->validated('resource_id'));
        }

        if ($request->filled('actor_user_id')) {
            $query->where('actor_user_id', (int) $request->validated('actor_user_id'));
        }

        $perPage = (int) ($request->validated('per_page') ?? self::DEFAULT_PER_PAGE);

        $logs = $query->with(['actor', 'restaurant'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage);

        return response()->json([
            'data' => [
                'audit_logs' => AuditLogResource::collection($logs->items()),
            ],
            'meta' => [
                'current_page' => $logs->currentPage(),
                'per_page' => $logs->perPage(),
                'total' => $logs->total(),
                'last_page' => $logs->lastPage(),
            ],
        ]);
    }
}
