<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\LoginRequest;
use App\Http\Resources\Api\V1\Auth\AuthContextResource;
use App\Models\User;
use App\Support\Auth\AuthContextBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use OpenApi\Attributes as OA;

class AuthController extends Controller
{
    /**
     * Authenticate the user using the "web" guard and start a session.
     */
    #[OA\Post(
        path: '/api/v1/auth/login',
        operationId: 'authLogin',
        summary: 'Authenticate a user',
        tags: ['Authentication'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['email', 'password'],
                properties: [
                    new OA\Property(property: 'email', type: 'string', format: 'email', example: 'user@example.com'),
                    new OA\Property(property: 'password', type: 'string', format: 'password', example: 'password'),
                    new OA\Property(property: 'remember', type: 'boolean', default: false),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Authenticated successfully',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'Authenticated successfully.'),
                        new OA\Property(
                            property: 'data',
                            properties: [
                                new OA\Property(property: 'user', ref: '#/components/schemas/User'),
                            ],
                            type: 'object'
                        ),
                    ]
                )
            ),
            new OA\Response(
                response: 401,
                description: 'Invalid credentials',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'Invalid credentials.'),
                    ]
                )
            ),
            new OA\Response(
                response: 403,
                description: 'This account has been suspended',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: 'This account has been suspended.'),
                    ]
                )
            ),
            new OA\Response(response: 422, description: 'Validation error'),
            new OA\Response(response: 429, description: 'Too many login attempts'),
        ]
    )]
    public function login(LoginRequest $request): JsonResponse
    {
        $credentials = $request->only('email', 'password');

        if (! Auth::guard('web')->attempt($credentials, $request->boolean('remember'))) {
            return response()->json([
                'message' => 'Invalid credentials.',
            ], 401);
        }

        // A suspended account must not authenticate, full stop — see
        // User::isSuspended()/EnsureUserIsActive for the same rule
        // enforced on every subsequent authenticated request. attempt()
        // above already started a session before this check could run,
        // so it must be torn down here rather than just refused.
        if (Auth::guard('web')->user()->status === User::STATUS_SUSPENDED) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return response()->json([
                'message' => 'This account has been suspended.',
            ], 403);
        }

        $request->session()->regenerate();

        return response()->json([
            'message' => 'Authenticated successfully.',
            'data' => [
                'user' => Auth::guard('web')->user(),
            ],
        ]);
    }

    /**
     * Return the currently authenticated user.
     */
    #[OA\Get(
        path: '/api/v1/auth/me',
        operationId: 'authMe',
        summary: 'Get the authenticated user',
        security: [['sessionCookie' => []]],
        tags: ['Authentication'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Authenticated user',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'data',
                            properties: [
                                new OA\Property(property: 'user', ref: '#/components/schemas/User'),
                            ],
                            type: 'object'
                        ),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'data' => [
                'user' => $request->user(),
            ],
        ]);
    }

    /**
     * Return the authenticated user's full authorization context: every
     * organization they belong to, the restaurants they can reach within
     * each, the permissions that apply at each of those two scopes, and
     * their platform-level access (kept separate from tenant access) —
     * see AuthContextBuilder.
     *
     * Deliberately outside the `tenant` middleware group: resolving "which
     * organizations can this user reach" cannot depend on an already-
     * resolved active organization (see ResolveTenant, which just takes
     * the user's first organization) — that would be circular, and would
     * make this endpoint unusable at initial login, before any
     * organization has been chosen. Every organization/restaurant/
     * permission returned here is instead derived directly from the
     * user's own membership and role rows, scoped exactly like every
     * other tenant endpoint (RestaurantScope, hasPermission()) — no
     * organization or restaurant belonging to another tenant is ever
     * reachable through this response.
     */
    #[OA\Get(
        path: '/api/v1/auth/context',
        operationId: 'authContext',
        summary: "Get the authenticated user's full authorization context",
        description: 'Returns every organization the user belongs to, the restaurants reachable within each, the permissions effective at each scope, and platform-level access. Intended for app bootstrap / login, not for frequent polling — see /auth/me for a lightweight identity check.',
        security: [['sessionCookie' => []]],
        tags: ['Authentication'],
        responses: [
            new OA\Response(
                response: 200,
                description: "The authenticated user's authorization context",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', ref: '#/components/schemas/AuthContext'),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function context(Request $request, AuthContextBuilder $authContextBuilder): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'data' => new AuthContextResource($user, $authContextBuilder->build($user)),
        ]);
    }

    /**
     * Log the user out and invalidate the session.
     */
    #[OA\Post(
        path: '/api/v1/auth/logout',
        operationId: 'authLogout',
        summary: 'Log out the authenticated user',
        security: [['sessionCookie' => []]],
        tags: ['Authentication'],
        responses: [
            new OA\Response(response: 204, description: 'Logged out successfully'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function logout(Request $request): Response
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }
}
