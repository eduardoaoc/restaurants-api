<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Invitations\AcceptInvitationAction;
use App\Exceptions\Invitations\InvitationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Invitations\AcceptExistingInvitationRequest;
use App\Http\Requests\Api\V1\Invitations\AcceptInvitationRequest;
use App\Http\Requests\Api\V1\Invitations\PreviewInvitationRequest;
use App\Models\Invitation;
use App\Models\Restaurant;
use App\Models\User;
use App\Support\Invitations\InvitationTokens;
use App\Support\Tenancy\ActiveOrganization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use OpenApi\Attributes as OA;

/**
 * The invitee's side: preview, then explicit acceptance.
 *
 * - preview never changes anything (no consumption, no session);
 * - the token is only ever read from the JSON body;
 * - accept (new account) and accept-existing (password of the existing
 *   account) both sign the person in on success and make the invitation's
 *   organization the session's active organization.
 *
 * Responses describe only the invitation itself (organization/restaurant
 * names, role, its own e-mail) — never other organizations of that account.
 */
class InvitationController extends Controller
{
    public function __construct(private readonly AcceptInvitationAction $acceptInvitation) {}

    #[OA\Post(
        path: '/api/v1/invitations/preview',
        operationId: 'invitationsPreview',
        summary: 'Read an invitation without consuming it (POST so the token never appears in a URL)',
        tags: ['Invitations'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['token'], properties: [new OA\Property(property: 'token', description: 'Secret from the e-mail link fragment. Sent only in the body, never in a URL.', type: 'string', example: 'token-from-the-e-mail-link')])),
        responses: [
            new OA\Response(response: 200, description: 'Invitation summary', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', properties: [new OA\Property(property: 'invitation', ref: '#/components/schemas/InvitationPreview')], type: 'object')])),
            new OA\Response(response: 404, description: 'INVITATION_NOT_FOUND (unknown, replaced or used token)', content: new OA\JsonContent(ref: '#/components/schemas/DomainError')),
            new OA\Response(response: 410, description: 'INVITATION_EXPIRED | INVITATION_REVOKED | INVITATION_ALREADY_ACCEPTED', content: new OA\JsonContent(ref: '#/components/schemas/DomainError')),
            new OA\Response(response: 422, description: 'Validation error'),
            new OA\Response(response: 429, description: 'Too many requests'),
        ]
    )]
    public function preview(PreviewInvitationRequest $request): JsonResponse
    {
        $invitation = InvitationTokens::find($request->validated('token')) ?? throw InvitationException::notFound();

        $this->acceptInvitation->assertUsable($invitation);

        $invitation->loadMissing(['organization', 'role']);
        $restaurantNames = $invitation->type === Invitation::TYPE_STAFF
            ? Restaurant::query()->whereIn('id', $invitation->restaurantIds())->orderBy('name')->pluck('name')->all()
            : Restaurant::query()->where('organization_id', $invitation->organization_id)->orderBy('name')->pluck('name')->all();

        return response()->json([
            'data' => [
                'invitation' => [
                    'type' => $invitation->type,
                    'email' => $invitation->email,
                    'name' => $invitation->name,
                    'role' => $invitation->role->slug,
                    'organization' => ['name' => $invitation->organization->name],
                    'restaurants' => array_map(fn (string $name) => ['name' => $name], $restaurantNames),
                    'expires_at' => $invitation->expires_at->toIso8601String(),
                    // Decides which form the page shows (create password vs
                    // sign in). Only about the invitation's own e-mail.
                    'account_exists' => User::query()->whereRaw('lower(email) = ?', [$invitation->email])->exists(),
                ],
            ],
        ]);
    }

    #[OA\Post(
        path: '/api/v1/invitations/accept',
        operationId: 'invitationsAccept',
        summary: 'Accept with a NEW account (name + own password); signs in and selects the invited organization',
        tags: ['Invitations'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['token', 'name', 'password', 'password_confirmation'], properties: [
            new OA\Property(property: 'token', description: 'Secret from the e-mail link fragment. Sent only in the body, never in a URL.', type: 'string', example: 'token-from-the-e-mail-link'),
            new OA\Property(property: 'name', type: 'string'),
            new OA\Property(property: 'password', type: 'string', format: 'password', minLength: 8),
            new OA\Property(property: 'password_confirmation', type: 'string', format: 'password'),
        ])),
        responses: [
            new OA\Response(response: 200, description: 'Accepted and signed in', content: new OA\JsonContent(properties: [new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', properties: [new OA\Property(property: 'user', ref: '#/components/schemas/User'), new OA\Property(property: 'organization_id', type: 'integer')], type: 'object')])),
            new OA\Response(response: 403, description: 'INVITATION_ACCOUNT_SUSPENDED', content: new OA\JsonContent(ref: '#/components/schemas/DomainError')),
            new OA\Response(response: 404, description: 'INVITATION_NOT_FOUND (unknown, replaced or used token)', content: new OA\JsonContent(ref: '#/components/schemas/DomainError')),
            new OA\Response(response: 409, description: 'INVITATION_ACCOUNT_EXISTS | INVITATION_ACCOUNT_MISMATCH | INVITATION_ORGANIZATION_UNAVAILABLE | INVITATION_MEMBERSHIP_CONFLICT | INVITATION_ASSIGNMENT_CONFLICT | INVITATION_NOT_ALLOWED_FOR_ACCOUNT', content: new OA\JsonContent(ref: '#/components/schemas/DomainError')),
            new OA\Response(response: 410, description: 'INVITATION_EXPIRED | INVITATION_REVOKED | INVITATION_ALREADY_ACCEPTED', content: new OA\JsonContent(ref: '#/components/schemas/DomainError')),
            new OA\Response(response: 422, description: 'Validation error'),
            new OA\Response(response: 429, description: 'Too many requests'),
        ]
    )]
    public function accept(AcceptInvitationRequest $request): JsonResponse
    {
        return $this->complete($request, AcceptInvitationAction::MODE_NEW, [
            'name' => $request->validated('name'),
            'password' => $request->validated('password'),
        ]);
    }

    #[OA\Post(
        path: '/api/v1/invitations/accept-existing',
        operationId: 'invitationsAcceptExisting',
        summary: 'Accept with the EXISTING account of the invitation e-mail (its current password); name/password unchanged',
        tags: ['Invitations'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['token', 'password'], properties: [
            new OA\Property(property: 'token', description: 'Secret from the e-mail link fragment. Sent only in the body, never in a URL.', type: 'string', example: 'token-from-the-e-mail-link'),
            new OA\Property(property: 'password', type: 'string', format: 'password'),
        ])),
        responses: [
            new OA\Response(response: 200, description: 'Accepted and signed in', content: new OA\JsonContent(properties: [new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', properties: [new OA\Property(property: 'user', ref: '#/components/schemas/User'), new OA\Property(property: 'organization_id', type: 'integer')], type: 'object')])),
            new OA\Response(response: 403, description: 'INVITATION_ACCOUNT_SUSPENDED', content: new OA\JsonContent(ref: '#/components/schemas/DomainError')),
            new OA\Response(response: 404, description: 'INVITATION_NOT_FOUND (unknown, replaced or used token)', content: new OA\JsonContent(ref: '#/components/schemas/DomainError')),
            new OA\Response(response: 409, description: 'INVITATION_ACCOUNT_REQUIRED | INVITATION_ACCOUNT_MISMATCH | INVITATION_ORGANIZATION_UNAVAILABLE | INVITATION_MEMBERSHIP_CONFLICT | INVITATION_ASSIGNMENT_CONFLICT | INVITATION_NOT_ALLOWED_FOR_ACCOUNT', content: new OA\JsonContent(ref: '#/components/schemas/DomainError')),
            new OA\Response(response: 410, description: 'INVITATION_EXPIRED | INVITATION_REVOKED | INVITATION_ALREADY_ACCEPTED', content: new OA\JsonContent(ref: '#/components/schemas/DomainError')),
            new OA\Response(response: 422, description: 'Validation error or wrong password (field password)'),
            new OA\Response(response: 429, description: 'Too many requests'),
        ]
    )]
    public function acceptExisting(AcceptExistingInvitationRequest $request): JsonResponse
    {
        return $this->complete($request, AcceptInvitationAction::MODE_EXISTING, [
            'password' => $request->validated('password'),
        ]);
    }

    /**
     * @param  array{name?: string, password: string}  $input
     */
    private function complete(Request $request, string $mode, array $input): JsonResponse
    {
        $result = $this->acceptInvitation->execute(
            $request->input('token'),
            $mode,
            $input,
            Auth::guard('web')->user(),
        );

        Auth::guard('web')->login($result['user']);
        $request->session()->regenerate();
        ActiveOrganization::remember($request, $result['invitation']->organization_id);

        return response()->json([
            'message' => 'Invitation accepted.',
            'data' => [
                'user' => $result['user']->only(['id', 'name', 'email', 'email_verified_at', 'created_at', 'updated_at']),
                'organization_id' => $result['invitation']->organization_id,
            ],
        ]);
    }
}
