<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\WhatsApp\UpdateDayCloseWhatsAppSettingsAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\WhatsApp\UpdateDayCloseWhatsAppSettingsRequest;
use App\Models\Organization;
use App\Models\Restaurant;
use App\Models\RestaurantReportRecipient;
use App\Models\User;
use App\Support\DayClose\DayCloseFormat;
use App\Support\Restaurants\RestaurantScope;
use App\Support\Tenancy\TenantContext;
use App\Support\WhatsApp\DayCloseDeliveryService;
use App\Support\WhatsApp\WhatsAppConfig;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * Cierre Diario WhatsApp delivery configuration of a restaurant (CARTA
 * 9.1E): the switch and its single active recipient. manage_restaurants
 * (RestaurantPolicy::manageSettings) + RestaurantScope; out-of-scope
 * restaurant = 404. The full phone number is accepted on update but NEVER
 * returned — only phone_masked.
 */
class DayCloseWhatsAppSettingsController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly UpdateDayCloseWhatsAppSettingsAction $updateSettings,
        private readonly WhatsAppConfig $config,
    ) {}

    #[OA\Get(
        path: '/api/v1/restaurants/{restaurant}/day-close-whatsapp-settings',
        operationId: 'dayCloseWhatsAppSettingsShow',
        summary: 'Get the Cierre Diario WhatsApp delivery settings of a restaurant',
        security: [['sessionCookie' => []]],
        tags: ['Cierre Diario'],
        parameters: [new OA\Parameter(name: 'restaurant', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Settings', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/DayCloseWhatsAppSettings')])),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Requires manage_restaurants'),
            new OA\Response(response: 404, description: 'Restaurant not found in the user\'s scope'),
        ]
    )]
    public function show(Request $request, int $restaurant): JsonResponse
    {
        $restaurantModel = $this->restaurantQuery($this->activeOrganization(), $request->user())->findOrFail($restaurant);

        $this->authorize('manageSettings', $restaurantModel);

        return response()->json(['data' => $this->payload($restaurantModel, DayCloseDeliveryService::activeRecipient($restaurantModel->id))]);
    }

    #[OA\Put(
        path: '/api/v1/restaurants/{restaurant}/day-close-whatsapp-settings',
        operationId: 'dayCloseWhatsAppSettingsUpdate',
        summary: 'Configure the Cierre Diario WhatsApp delivery of a restaurant',
        description: 'recipient=null disables the current recipient (its consent is revoked). A new or changed phone number requires recipient.consent_confirmed=true — the admin confirms: "Confirmo que esta persona ha aceptado recibir los cierres diarios por WhatsApp." (consent_text_version v1). Consent never migrates to another number. enabled=true requires an active recipient with valid consent.',
        security: [['sessionCookie' => []]],
        tags: ['Cierre Diario'],
        parameters: [new OA\Parameter(name: 'restaurant', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['enabled', 'recipient'],
            properties: [
                new OA\Property(property: 'enabled', type: 'boolean'),
                new OA\Property(property: 'recipient', nullable: true, properties: [
                    new OA\Property(property: 'name', type: 'string', maxLength: 100, example: 'Carlos Ruiz'),
                    new OA\Property(property: 'phone', type: 'string', nullable: true, example: '+34612345678', description: 'Strict E.164. Required for a new recipient; omit to keep the current number. Write-only: never returned.'),
                    new OA\Property(property: 'user_id', type: 'integer', nullable: true, description: 'Optional AFORO user of the same organization.'),
                    new OA\Property(property: 'consent_confirmed', type: 'boolean', description: 'Required (true) for a new or changed number.'),
                ], type: 'object'),
            ]
        )),
        responses: [
            new OA\Response(response: 200, description: 'Updated settings', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/DayCloseWhatsAppSettings')])),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Requires manage_restaurants'),
            new OA\Response(response: 404, description: 'Restaurant not found in the user\'s scope'),
            new OA\Response(response: 422, description: 'Validation error (E.164, consent required for a new number, enabling without a usable recipient)'),
        ]
    )]
    public function update(UpdateDayCloseWhatsAppSettingsRequest $request, int $restaurant): JsonResponse
    {
        $restaurantModel = $this->restaurantQuery($this->activeOrganization(), $request->user())->findOrFail($restaurant);

        $this->authorize('manageSettings', $restaurantModel);

        $recipient = $this->updateSettings->execute($restaurantModel, $request->user(), $request->validated());

        return response()->json(['data' => $this->payload($restaurantModel->refresh(), $recipient)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Restaurant $restaurant, ?RestaurantReportRecipient $recipient): array
    {
        $enabled = (bool) $restaurant->settings->daily_close_whatsapp_enabled;

        return [
            'enabled' => $enabled,
            'available' => $this->config->readyToSend(),
            'status' => match (true) {
                ! $enabled => 'disabled',
                ! $this->config->readyToSend() || $recipient === null || ! $recipient->isUsable() => 'configuration_required',
                default => 'ready',
            },
            'consent_text' => RestaurantReportRecipient::CONSENT_TEXT,
            'consent_text_version' => RestaurantReportRecipient::CONSENT_TEXT_VERSION,
            'recipient' => $recipient === null ? null : [
                'id' => $recipient->id,
                'name' => $recipient->name,
                'phone_masked' => $recipient->phone_masked,
                'linked_user_id' => $recipient->user_id,
                'active' => $recipient->active,
                'consent_given_at' => DayCloseFormat::instant($recipient->consent_given_at),
                'consent_method' => $recipient->consent_method,
                'consent_text_version' => $recipient->consent_text_version,
            ],
        ];
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
