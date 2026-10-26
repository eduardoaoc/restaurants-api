<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Invitations\CreateInvitationAction;
use App\Actions\Invitations\ResendInvitationAction;
use App\Actions\Invitations\RevokeInvitationAction;
use App\Exceptions\Invitations\InvitationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Staff\StoreStaffInvitationRequest;
use App\Http\Resources\Api\V1\InvitationResource;
use App\Models\AuditLog;
use App\Models\Invitation;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\Restaurant;
use App\Models\RestaurantSettings;
use App\Models\Role;
use App\Models\User;
use App\Support\Invitations\InvitationMailer;
use App\Support\Restaurants\RestaurantScope;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OA;

/**
 * Staff invitations of the ACTIVE organization — the invite-first way to
 * add someone to the team (they set their own password on acceptance).
 *
 * Authorization reuses StaffPolicy exactly: creating, resending or revoking
 * needs StaffPolicy::create (manage_users + reachability) at EVERY
 * restaurant of the invitation; a restaurant outside the requester's
 * RestaurantScope resolves as 404 before any permission check, like
 * StaffController. Owner invitations (platform-managed) are never
 * reachable here.
 */
class StaffInvitationController extends Controller
{
    private const LIST_LIMIT = 200;

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly CreateInvitationAction $createInvitation,
        private readonly ResendInvitationAction $resendInvitation,
        private readonly RevokeInvitationAction $revokeInvitation,
        private readonly InvitationMailer $mailer,
    ) {}

    #[OA\Get(
        path: '/api/v1/staff/invitations',
        operationId: 'staffInvitationsIndex',
        summary: 'List staff invitations whose every restaurant the requester manages (manage_users)',
        security: [['sessionCookie' => []]],
        tags: ['Staff'],
        responses: [
            new OA\Response(response: 200, description: 'Invitations, newest first (max 200)', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', properties: [new OA\Property(property: 'invitations', type: 'array', items: new OA\Items(ref: '#/components/schemas/Invitation'))], type: 'object')])),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Missing permission'),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $organization = $this->activeOrganization();

        $this->authorize('viewAny', [User::class, $organization]);

        $permitted = RestaurantScope::restaurantIdsWithAnyPermission($request->user(), $organization, ['manage_users']);

        $invitations = $this->staffInvitations($organization)
            ->orderByDesc('id')
            ->limit(self::LIST_LIMIT)
            ->get()
            ->filter(fn (Invitation $invitation) => array_diff($invitation->restaurantIds(), $permitted) === [])
            ->values();

        InvitationResource::preloadRestaurantNames($invitations);

        return response()->json([
            'data' => ['invitations' => InvitationResource::collection($invitations)],
        ]);
    }

    #[OA\Post(
        path: '/api/v1/staff/invitations',
        operationId: 'staffInvitationsStore',
        summary: 'Invite a staff member (no password: they choose it on acceptance)',
        security: [['sessionCookie' => []]],
        tags: ['Staff'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/StaffInvitationRequest')),
        responses: [
            new OA\Response(response: 201, description: 'Invitation created and e-mail queued', content: new OA\JsonContent(properties: [new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', properties: [new OA\Property(property: 'invitation', ref: '#/components/schemas/Invitation')], type: 'object')])),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'manage_users missing at one of the restaurants'),
            new OA\Response(response: 404, description: 'A restaurant is outside the requester scope'),
            new OA\Response(response: 409, description: 'STAFF_ALREADY_MEMBER | STAFF_MEMBERSHIP_INACTIVE | INVITATION_ALREADY_PENDING (with invitation_id)', content: new OA\JsonContent(ref: '#/components/schemas/DomainError')),
            new OA\Response(response: 422, description: 'Validation error (role outside manager/waiter/kitchen/cashier, restaurant of another organization, employee code taken or reserved)'),
            new OA\Response(response: 429, description: 'Too many requests'),
        ]
    )]
    public function store(StoreStaffInvitationRequest $request): JsonResponse
    {
        $organization = $this->activeOrganization();
        $assignments = $request->assignments();

        $restaurants = $this->authorizeRestaurants($organization, $request->user(), array_column($assignments, 'restaurant_id'));

        $email = $request->validated('email');
        $this->assertNotAlreadyInTeam($organization, $email);

        try {
            $invitation = DB::transaction(fn () => $this->createInvitation->execute(
                organization: $organization,
                type: Invitation::TYPE_STAFF,
                email: $email,
                name: $request->validated('name'),
                role: Role::query()->where('slug', $request->validated('role'))->firstOrFail(),
                assignments: $assignments,
                locale: RestaurantSettings::query()->where('restaurant_id', $restaurants->first()->id)->value('default_locale') ?? RestaurantSettings::DEFAULT_LOCALE,
                inviter: $request->user(),
                invitedByType: Invitation::INVITED_BY_USER,
            ));
        } catch (UniqueConstraintViolationException) {
            $pending = $this->staffInvitations($organization)->where('status', Invitation::STATUS_PENDING)->where('email', $email)->first();

            throw InvitationException::alreadyPending($pending?->id ?? 0);
        }

        $this->mailer->queue($invitation);

        return response()->json([
            'message' => 'Invitation created.',
            'data' => ['invitation' => $this->present($invitation->refresh())],
        ], 201);
    }

    #[OA\Post(
        path: '/api/v1/staff/invitations/{invitation}/resend',
        operationId: 'staffInvitationsResend',
        summary: 'Resend a staff invitation: new link (previous one stops working), renewed expiry, same record',
        security: [['sessionCookie' => []]],
        tags: ['Staff'],
        parameters: [new OA\Parameter(name: 'invitation', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Updated invitation', content: new OA\JsonContent(properties: [new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', properties: [new OA\Property(property: 'invitation', ref: '#/components/schemas/Invitation')], type: 'object')])),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Missing permission'),
            new OA\Response(response: 404, description: 'Not found, owner invitation, or a restaurant outside the requester scope'),
            new OA\Response(response: 409, description: 'INVITATION_NOT_PENDING', content: new OA\JsonContent(ref: '#/components/schemas/DomainError')),
            new OA\Response(response: 429, description: 'INVITATION_RESEND_TOO_SOON (Retry-After) or too many requests', content: new OA\JsonContent(ref: '#/components/schemas/DomainError')),
        ]
    )]
    public function resend(Request $request, int $invitation): JsonResponse
    {
        $invitationModel = $this->manageableInvitation($request->user(), $invitation);

        $updated = $this->resendInvitation->execute($invitationModel, $request->user(), AuditLog::ACTOR_USER);

        return response()->json([
            'message' => 'Invitation queued again.',
            'data' => ['invitation' => $this->present($updated)],
        ]);
    }

    #[OA\Post(
        path: '/api/v1/staff/invitations/{invitation}/revoke',
        operationId: 'staffInvitationsRevoke',
        summary: 'Revoke a pending staff invitation',
        security: [['sessionCookie' => []]],
        tags: ['Staff'],
        parameters: [new OA\Parameter(name: 'invitation', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Updated invitation', content: new OA\JsonContent(properties: [new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', properties: [new OA\Property(property: 'invitation', ref: '#/components/schemas/Invitation')], type: 'object')])),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Missing permission'),
            new OA\Response(response: 404, description: 'Not found, owner invitation, or a restaurant outside the requester scope'),
            new OA\Response(response: 409, description: 'INVITATION_NOT_PENDING', content: new OA\JsonContent(ref: '#/components/schemas/DomainError')),
        ]
    )]
    public function revoke(Request $request, int $invitation): JsonResponse
    {
        $invitationModel = $this->manageableInvitation($request->user(), $invitation);

        $updated = $this->revokeInvitation->execute($invitationModel, $request->user(), AuditLog::ACTOR_USER);

        return response()->json([
            'message' => 'Invitation revoked.',
            'data' => ['invitation' => $this->present($updated)],
        ]);
    }

    private function activeOrganization(): Organization
    {
        return Organization::query()->findOrFail($this->tenantContext->getOrganizationId());
    }

    private function staffInvitations(Organization $organization)
    {
        return Invitation::query()->with('role')
            ->where('organization_id', $organization->id)
            ->where('type', Invitation::TYPE_STAFF);
    }

    /**
     * Scope first (404), then StaffPolicy::create at every restaurant (403).
     *
     * @param  array<int, int>  $restaurantIds
     * @return Collection<int, Restaurant>
     */
    private function authorizeRestaurants(Organization $organization, User $requester, array $restaurantIds): Collection
    {
        $accessible = RestaurantScope::accessibleRestaurantIds($requester, $organization);

        $restaurants = new Collection(array_map(
            fn (int $id) => Restaurant::query()
                ->where('organization_id', $organization->id)
                ->when($accessible !== null, fn ($query) => $query->whereIn('id', $accessible))
                ->findOrFail($id),
            $restaurantIds,
        ));

        $restaurants->each(fn (Restaurant $restaurant) => $this->authorize('create', [User::class, $organization, $restaurant]));

        return $restaurants;
    }

    private function manageableInvitation(User $requester, int $id): Invitation
    {
        $organization = $this->activeOrganization();
        $invitation = $this->staffInvitations($organization)->findOrFail($id);

        $this->authorizeRestaurants($organization, $requester, $invitation->restaurantIds());

        return $invitation;
    }

    /**
     * Only THIS organization's membership is consulted — whether the e-mail
     * has an account or memberships elsewhere is never revealed.
     */
    private function assertNotAlreadyInTeam(Organization $organization, string $email): void
    {
        $membershipStatus = OrganizationUser::query()
            ->where('organization_id', $organization->id)
            ->whereHas('user', fn ($query) => $query->whereRaw('lower(email) = ?', [$email]))
            ->value('status');

        if ($membershipStatus === OrganizationUser::STATUS_ACTIVE) {
            throw InvitationException::staffAlreadyMember();
        }

        if ($membershipStatus === OrganizationUser::STATUS_INACTIVE) {
            throw InvitationException::staffMembershipInactive();
        }
    }

    private function present(Invitation $invitation): InvitationResource
    {
        $invitation->loadMissing('role');
        InvitationResource::preloadRestaurantNames([$invitation]);

        return new InvitationResource($invitation);
    }
}
