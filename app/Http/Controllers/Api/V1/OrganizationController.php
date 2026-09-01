<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Organization\UpdateOrganizationRequest;
use App\Http\Resources\Api\V1\OrganizationResource;
use App\Models\Organization;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

class OrganizationController extends Controller
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    /**
     * Return the active organization for the authenticated user.
     */
    #[OA\Get(
        path: '/api/v1/organization',
        operationId: 'organizationShow',
        summary: 'Get the active organization',
        security: [['sessionCookie' => []]],
        tags: ['Organization'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'The active organization',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'data',
                            properties: [
                                new OA\Property(property: 'organization', ref: '#/components/schemas/Organization'),
                            ],
                            type: 'object'
                        ),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'The user has no organization'),
        ]
    )]
    public function show(): JsonResponse
    {
        $organization = $this->activeOrganization();

        $this->authorize('view', $organization);

        return response()->json([
            'data' => [
                'organization' => new OrganizationResource($organization),
            ],
        ]);
    }

    /**
     * Update the active organization for the authenticated user.
     */
    #[OA\Patch(
        path: '/api/v1/organization',
        operationId: 'organizationUpdate',
        summary: 'Update the active organization',
        security: [['sessionCookie' => []]],
        tags: ['Organization'],
        requestBody: new OA\RequestBody(
            required: false,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'name', type: 'string', example: 'Grupo Exemplo'),
                    new OA\Property(property: 'slug', type: 'string', example: 'grupo-exemplo'),
                    new OA\Property(property: 'status', type: 'string', example: 'active'),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Organization updated successfully',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'Organization updated successfully.'),
                        new OA\Property(
                            property: 'data',
                            properties: [
                                new OA\Property(property: 'organization', ref: '#/components/schemas/Organization'),
                            ],
                            type: 'object'
                        ),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'The user is not allowed to update the organization'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function update(UpdateOrganizationRequest $request): JsonResponse
    {
        $organization = $this->activeOrganization();

        $this->authorize('update', $organization);

        $organization->update($request->validated());

        return response()->json([
            'message' => 'Organization updated successfully.',
            'data' => [
                'organization' => new OrganizationResource($organization),
            ],
        ]);
    }

    /**
     * Resolve the active organization from the tenant context.
     */
    private function activeOrganization(): Organization
    {
        return Organization::query()->findOrFail($this->tenantContext->getOrganizationId());
    }
}
