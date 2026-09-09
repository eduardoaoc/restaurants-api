<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Platform\IndexPlatformUsersRequest;
use App\Http\Requests\Api\V1\Platform\UpdatePlatformUserStatusRequest;
use App\Http\Resources\Api\V1\Platform\PlatformUserResource;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\Audit\PlatformAuditLogger;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OA;

/**
 * Global (cross-tenant) user administration for platform admins. Every
 * action here is gated by the `platform_admin` route middleware plus a
 * platform.users.* Gate (see PlatformAuthServiceProvider) — never by a
 * tenant role or RestaurantScope, which do not apply in this namespace.
 */
class PlatformUserController extends Controller
{
    private const DEFAULT_PER_PAGE = 25;

    public function __construct(private readonly PlatformAuditLogger $auditLogger) {}

    #[OA\Get(
        path: '/api/v1/platform/users',
        operationId: 'platformUsersIndex',
        summary: 'List users globally, across every organization',
        security: [['sessionCookie' => []]],
        tags: ['Platform Admin'],
        parameters: [
            new OA\Parameter(name: 'search', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', example: 'active')),
            new OA\Parameter(name: 'organization_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 25)),
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'A page of users'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Platform administrator access required'),
        ]
    )]
    public function index(IndexPlatformUsersRequest $request): JsonResponse
    {
        $this->authorize('platform.users.view');

        $query = User::query()->with(['organizations', 'roles', 'platformRoles']);

        if ($request->filled('search')) {
            $search = $request->validated('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->validated('status'));
        }

        if ($request->filled('organization_id')) {
            $organizationId = (int) $request->validated('organization_id');
            $query->whereHas('organizations', fn ($q) => $q->whereKey($organizationId));
        }

        $perPage = (int) ($request->validated('per_page') ?? self::DEFAULT_PER_PAGE);

        $users = $query->orderByDesc('id')->paginate($perPage);

        return response()->json([
            'data' => [
                'users' => PlatformUserResource::collection($users->items()),
            ],
            'meta' => $this->paginationMeta($users),
        ]);
    }

    #[OA\Get(
        path: '/api/v1/platform/users/{user}',
        operationId: 'platformUsersShow',
        summary: 'Get one user, regardless of which organization(s) they belong to',
        security: [['sessionCookie' => []]],
        tags: ['Platform Admin'],
        parameters: [
            new OA\Parameter(name: 'user', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The user'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Platform administrator access required'),
            new OA\Response(response: 404, description: 'User not found'),
        ]
    )]
    public function show(int $user): JsonResponse
    {
        $this->authorize('platform.users.view');

        $userModel = User::query()->with(['organizations', 'roles', 'platformRoles'])->findOrFail($user);

        return response()->json([
            'data' => [
                'user' => new PlatformUserResource($userModel),
            ],
        ]);
    }

    /**
     * Suspending deletes the user's `sessions` rows so an already
     * authenticated browser session dies immediately (see
     * EnsureUserIsActive for the defense-in-depth check on top of this).
     * Self-suspension is refused outright — see the Bloco 0 report's
     * "protection of the platform admin" section for why this is the one
     * self-protection reachable today (there is no endpoint yet to revoke
     * a platform role, so "never leave the platform without an admin" has
     * nothing else to guard).
     */
    #[OA\Patch(
        path: '/api/v1/platform/users/{user}/status',
        operationId: 'platformUsersUpdateStatus',
        summary: 'Suspend or reactivate a user account globally',
        security: [['sessionCookie' => []]],
        tags: ['Platform Admin'],
        parameters: [
            new OA\Parameter(name: 'user', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['status', 'reason'],
                properties: [
                    new OA\Property(property: 'status', type: 'string', example: 'suspended'),
                    new OA\Property(property: 'reason', type: 'string', example: 'Reported abusive behavior — ticket #482.'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'User status updated successfully'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Platform administrator access required, or attempted self-suspension'),
            new OA\Response(response: 404, description: 'User not found'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function updateStatus(UpdatePlatformUserStatusRequest $request, int $user): JsonResponse
    {
        $this->authorize('platform.users.manage');

        $userModel = User::query()->findOrFail($user);

        $newStatus = $request->validated('status');
        $reason = $request->validated('reason');

        if ($userModel->id === $request->user()->id && $newStatus === User::STATUS_SUSPENDED) {
            abort(403, 'You cannot suspend your own account.');
        }

        $oldStatus = $userModel->status;
        $organizationId = $userModel->organizations()->first()?->id;

        DB::transaction(function () use ($userModel, $oldStatus, $newStatus, $organizationId, $reason, $request) {
            $userModel->update(['status' => $newStatus]);

            if ($newStatus === User::STATUS_SUSPENDED) {
                DB::table('sessions')->where('user_id', $userModel->id)->delete();
            }

            $this->auditLogger->log(
                actor: $request->user(),
                event: $newStatus === User::STATUS_SUSPENDED
                    ? AuditLog::EVENT_PLATFORM_USER_SUSPENDED
                    : AuditLog::EVENT_PLATFORM_USER_REACTIVATED,
                resourceType: AuditLog::RESOURCE_USER,
                resourceId: $userModel->id,
                organizationId: $organizationId,
                restaurantId: null,
                reason: $reason,
                changes: ['status' => ['old' => $oldStatus, 'new' => $newStatus]],
            );
        });

        return response()->json([
            'message' => 'User status updated successfully.',
            'data' => [
                'user' => new PlatformUserResource($userModel->fresh(['organizations', 'roles', 'platformRoles'])),
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
