<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Actions\Invitations\CreateInvitationAction;
use App\Actions\Invitations\ResendInvitationAction;
use App\Actions\Invitations\RevokeInvitationAction;
use App\Exceptions\Invitations\InvitationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Platform\IndexPlatformInvitationsRequest;
use App\Http\Requests\Api\V1\Platform\StoreOwnerInvitationRequest;
use App\Http\Resources\Api\V1\InvitationResource;
use App\Models\AuditLog;
use App\Models\Invitation;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\RestaurantSettings;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use App\Support\Invitations\InvitationMailer;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

/**
 * Owner invitations, as managed by the platform. Staff invitations are a
 * tenant concern (StaffInvitationController) and are never reachable here:
 * every lookup is restricted to type=owner, anything else is 404.
 */
class PlatformInvitationController extends Controller
{
    private const DEFAULT_PER_PAGE = 25;

    public function __construct(
        private readonly CreateInvitationAction $createInvitation,
        private readonly ResendInvitationAction $resendInvitation,
        private readonly RevokeInvitationAction $revokeInvitation,
        private readonly InvitationMailer $mailer,
    ) {}

    #[OA\Get(
        path: '/api/v1/platform/invitations',
        operationId: 'platformInvitationsIndex',
        summary: 'List owner invitations (create_platform_restaurants)',
        security: [['sessionCookie' => []]],
        tags: ['Platform Admin'],
        parameters: [
            new OA\Parameter(name: 'organization_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'state', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['pending', 'expired', 'accepted', 'revoked'])),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', maximum: 100)),
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Owner invitations, newest first', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', properties: [new OA\Property(property: 'invitations', type: 'array', items: new OA\Items(ref: '#/components/schemas/Invitation'))], type: 'object'), new OA\Property(property: 'meta', type: 'object')])),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Missing permission'),
        ]
    )]
    public function index(IndexPlatformInvitationsRequest $request): JsonResponse
    {
        $this->authorize('platform.restaurants.create');

        $query = Invitation::query()->with(['organization', 'role'])->where('type', Invitation::TYPE_OWNER);

        if ($request->filled('organization_id')) {
            $query->where('organization_id', $request->integer('organization_id'));
        }

        match ($request->validated('state')) {
            Invitation::STATUS_PENDING => $query->where('status', Invitation::STATUS_PENDING)->where('expires_at', '>', now()),
            Invitation::STATE_EXPIRED => $query->where('status', Invitation::STATUS_PENDING)->where('expires_at', '<=', now()),
            Invitation::STATUS_ACCEPTED, Invitation::STATUS_REVOKED => $query->where('status', $request->validated('state')),
            default => null,
        };

        $page = $query->orderByDesc('id')->paginate((int) ($request->validated('per_page') ?? self::DEFAULT_PER_PAGE));

        return response()->json([
            'data' => ['invitations' => InvitationResource::collection($page->items())],
            'meta' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }

    /**
     * A new owner invitation for an organization that has NO active owner
     * (recovery after a revoked/mistyped first invitation). Not a way to add
     * co-owners or transfer ownership: refused once any active owner exists.
     */
    #[OA\Post(
        path: '/api/v1/platform/organizations/{organization}/owner-invitations',
        operationId: 'platformOwnerInvitationsStore',
        summary: 'Invite the owner of an organization that has no active owner (create_platform_restaurants + manage_platform_organizations)',
        security: [['sessionCookie' => []]],
        tags: ['Platform Admin'],
        parameters: [new OA\Parameter(name: 'organization', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['email'], properties: [
            new OA\Property(property: 'email', type: 'string', format: 'email', example: 'owner@example.com'),
            new OA\Property(property: 'name', type: 'string', nullable: true),
        ])),
        responses: [
            new OA\Response(response: 201, description: 'Invitation created and e-mail queued', content: new OA\JsonContent(properties: [new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', properties: [new OA\Property(property: 'invitation', ref: '#/components/schemas/Invitation')], type: 'object')])),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Missing permission'),
            new OA\Response(response: 404, description: 'Organization not found'),
            new OA\Response(response: 409, description: 'ORGANIZATION_HAS_OWNER | INVITATION_ALREADY_PENDING (with invitation_id) | ORGANIZATION_SUSPENDED', content: new OA\JsonContent(ref: '#/components/schemas/DomainError')),
            new OA\Response(response: 422, description: 'Validation error (incl. platform administrator e-mail)'),
            new OA\Response(response: 429, description: 'Too many requests'),
        ]
    )]
    public function storeOwner(StoreOwnerInvitationRequest $request, int $organization): JsonResponse
    {
        $this->authorize('platform.restaurants.create');
        $this->authorize('platform.organizations.manage');

        $organizationModel = Organization::query()->findOrFail($organization);
        $email = $request->validated('email');

        $account = User::query()->whereRaw('lower(email) = ?', [$email])->first();
        if ($account !== null && $account->isPlatformAdmin()) {
            throw ValidationException::withMessages(['email' => 'A platform administrator account cannot be the owner of a restaurant.']);
        }

        try {
            $invitation = DB::transaction(function () use ($organizationModel, $request, $email) {
                $locked = Organization::query()->lockForUpdate()->findOrFail($organizationModel->id);

                if ($locked->status === Organization::STATUS_SUSPENDED) {
                    throw InvitationException::organizationSuspended();
                }

                if ($this->hasActiveOwner($locked)) {
                    throw InvitationException::ownerAlreadyActive();
                }

                $pending = Invitation::query()->where('organization_id', $locked->id)
                    ->where('type', Invitation::TYPE_OWNER)->where('status', Invitation::STATUS_PENDING)->first();
                if ($pending !== null) {
                    throw InvitationException::alreadyPending($pending->id);
                }

                $locale = RestaurantSettings::query()->where('organization_id', $locked->id)->orderBy('restaurant_id')->value('default_locale')
                    ?? RestaurantSettings::DEFAULT_LOCALE;

                return $this->createInvitation->execute(
                    organization: $locked,
                    type: Invitation::TYPE_OWNER,
                    email: $email,
                    name: $request->validated('name'),
                    role: Role::query()->where('slug', 'owner')->firstOrFail(),
                    assignments: null,
                    locale: $locale,
                    inviter: $request->user(),
                    invitedByType: Invitation::INVITED_BY_PLATFORM_ADMIN,
                );
            });
        } catch (UniqueConstraintViolationException) {
            $pending = Invitation::query()->where('organization_id', $organizationModel->id)->where('status', Invitation::STATUS_PENDING)->where('email', $email)->firstOrFail();
            throw InvitationException::alreadyPending($pending->id);
        }

        $this->mailer->queue($invitation);

        return response()->json([
            'message' => 'Owner invitation created.',
            'data' => ['invitation' => new InvitationResource($invitation->refresh()->load(['organization', 'role']))],
        ], 201);
    }

    #[OA\Post(
        path: '/api/v1/platform/invitations/{invitation}/resend',
        operationId: 'platformInvitationsResend',
        summary: 'Resend an owner invitation (new link, renewed expiry, same record)',
        security: [['sessionCookie' => []]],
        tags: ['Platform Admin'],
        parameters: [new OA\Parameter(name: 'invitation', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Updated invitation', content: new OA\JsonContent(properties: [new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', properties: [new OA\Property(property: 'invitation', ref: '#/components/schemas/Invitation')], type: 'object')])),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Missing permission'),
            new OA\Response(response: 404, description: 'Not found (staff invitations are not reachable here)'),
            new OA\Response(response: 409, description: 'INVITATION_NOT_PENDING', content: new OA\JsonContent(ref: '#/components/schemas/DomainError')),
            new OA\Response(response: 429, description: 'INVITATION_RESEND_TOO_SOON (Retry-After) or too many requests', content: new OA\JsonContent(ref: '#/components/schemas/DomainError')),
        ]
    )]
    public function resend(int $invitation): JsonResponse
    {
        $this->authorize('platform.restaurants.create');

        $updated = $this->resendInvitation->execute($this->ownerInvitation($invitation), request()->user(), AuditLog::ACTOR_PLATFORM_ADMIN);

        return response()->json([
            'message' => 'Invitation queued again.',
            'data' => ['invitation' => new InvitationResource($updated->load(['organization', 'role']))],
        ]);
    }

    #[OA\Post(
        path: '/api/v1/platform/invitations/{invitation}/revoke',
        operationId: 'platformInvitationsRevoke',
        summary: 'Revoke a pending owner invitation',
        security: [['sessionCookie' => []]],
        tags: ['Platform Admin'],
        parameters: [new OA\Parameter(name: 'invitation', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Updated invitation', content: new OA\JsonContent(properties: [new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', properties: [new OA\Property(property: 'invitation', ref: '#/components/schemas/Invitation')], type: 'object')])),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Missing permission'),
            new OA\Response(response: 404, description: 'Not found (staff invitations are not reachable here)'),
            new OA\Response(response: 409, description: 'INVITATION_NOT_PENDING', content: new OA\JsonContent(ref: '#/components/schemas/DomainError')),
        ]
    )]
    public function revoke(int $invitation): JsonResponse
    {
        $this->authorize('platform.restaurants.create');

        $updated = $this->revokeInvitation->execute($this->ownerInvitation($invitation), request()->user(), AuditLog::ACTOR_PLATFORM_ADMIN);

        return response()->json([
            'message' => 'Invitation revoked.',
            'data' => ['invitation' => new InvitationResource($updated->load(['organization', 'role']))],
        ]);
    }

    private function ownerInvitation(int $id): Invitation
    {
        return Invitation::query()->where('type', Invitation::TYPE_OWNER)->findOrFail($id);
    }

    private function hasActiveOwner(Organization $organization): bool
    {
        return UserRole::query()
            ->where('organization_id', $organization->id)
            ->whereNull('restaurant_id')
            ->whereHas('role', fn ($query) => $query->where('slug', 'owner'))
            ->whereExists(fn ($query) => $query->selectRaw('1')->from('organization_users')
                ->whereColumn('organization_users.user_id', 'user_roles.user_id')
                ->where('organization_users.organization_id', $organization->id)
                ->where('organization_users.status', OrganizationUser::STATUS_ACTIVE))
            ->exists();
    }
}
