<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Actions\Platform\OnboardRestaurantAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Platform\OnboardPlatformRestaurantRequest;
use App\Http\Resources\Api\V1\InvitationResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

/**
 * Manual restaurant onboarding by a platform admin — see
 * OnboardRestaurantAction. Lives in the platform namespace (no `tenant`
 * middleware): the admin never becomes a member of the organization.
 */
class PlatformOnboardingController extends Controller
{
    public function __construct(private readonly OnboardRestaurantAction $onboard) {}

    #[OA\Post(
        path: '/api/v1/platform/restaurants',
        operationId: 'platformRestaurantsOnboard',
        summary: 'Onboard a restaurant (new or existing organization) and invite its owner',
        security: [['sessionCookie' => []]],
        tags: ['Platform Admin'],
        parameters: [
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string', maxLength: 100)),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'organization_id', type: 'integer', nullable: true),
                new OA\Property(property: 'organization', properties: [
                    new OA\Property(property: 'name', type: 'string'),
                    new OA\Property(property: 'slug', type: 'string'),
                ], type: 'object', nullable: true),
                new OA\Property(property: 'restaurant', properties: [
                    new OA\Property(property: 'name', type: 'string'),
                    new OA\Property(property: 'slug', type: 'string'),
                    new OA\Property(property: 'timezone', type: 'string', nullable: true),
                    new OA\Property(property: 'default_locale', type: 'string', enum: ['es-ES', 'ca-ES-valencia', 'en-GB'], nullable: true),
                ], type: 'object'),
                new OA\Property(property: 'owner', properties: [
                    new OA\Property(property: 'email', type: 'string', format: 'email'),
                    new OA\Property(property: 'name', type: 'string', nullable: true),
                ], type: 'object', nullable: true),
            ]
        )),
        responses: [
            new OA\Response(response: 201, description: 'Onboarded (organization/restaurant/settings + owner invitation for a new organization)', content: new OA\JsonContent(properties: [new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', ref: '#/components/schemas/OnboardingResult')])),
            new OA\Response(response: 200, description: 'Replay: same Idempotency-Key and same payload return the first result (data.replayed=true); nothing is created twice', content: new OA\JsonContent(properties: [new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', ref: '#/components/schemas/OnboardingResult')])),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Missing platform permission'),
            new OA\Response(response: 409, description: 'IDEMPOTENCY_KEY_REUSED (same key, different payload) | ORGANIZATION_SUSPENDED', content: new OA\JsonContent(ref: '#/components/schemas/DomainError')),
            new OA\Response(response: 429, description: 'Too many requests'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function store(OnboardPlatformRestaurantRequest $request): JsonResponse
    {
        $this->authorize('platform.restaurants.create');

        $data = $request->onboardingData();

        if (isset($data['organization_id'])) {
            // Choosing an existing tenant is an organization-level act.
            $this->authorize('platform.organizations.manage');
        }

        if (isset($data['owner']) && $this->isPlatformAccount($data['owner']['email'])) {
            throw ValidationException::withMessages([
                'owner.email' => 'A platform administrator account cannot be the owner of a restaurant.',
            ]);
        }

        $result = $this->onboard->execute($request->user(), $data, $request->validated('idempotency_key'));

        $invitation = $result['invitation']?->loadMissing(['organization', 'role']);
        if ($invitation !== null) {
            InvitationResource::preloadRestaurantNames([$invitation]);
        }

        $restaurant = $result['restaurant']->loadMissing('settings');

        return response()->json([
            'message' => $result['replayed'] ? 'Restaurant already onboarded.' : 'Restaurant onboarded successfully.',
            'data' => [
                'organization' => [
                    'id' => $result['organization']->id,
                    'name' => $result['organization']->name,
                    'slug' => $result['organization']->slug,
                    'status' => $result['organization']->status,
                    'created' => $result['organization_created'],
                ],
                'restaurant' => [
                    'id' => $restaurant->id,
                    'name' => $restaurant->name,
                    'slug' => $restaurant->slug,
                    'status' => $restaurant->status,
                    'timezone' => $restaurant->settings->timezone,
                    'currency' => $restaurant->settings->currency,
                    'default_locale' => $restaurant->settings->default_locale,
                ],
                'owner_invitation' => $invitation !== null ? new InvitationResource($invitation) : null,
                'replayed' => $result['replayed'],
            ],
        ], $result['replayed'] ? 200 : 201);
    }

    private function isPlatformAccount(string $email): bool
    {
        $user = User::query()->whereRaw('lower(email) = ?', [$email])->first();

        return $user !== null && $user->isPlatformAdmin();
    }
}
