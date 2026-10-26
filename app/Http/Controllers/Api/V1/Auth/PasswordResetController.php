<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\ForgotPasswordRequest;
use App\Http\Requests\Api\V1\Auth\ResetPasswordRequest;
use App\Models\AuditLog;
use App\Models\RestaurantSettings;
use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use App\Support\Audit\AuditLogger;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Passwords\PasswordBroker;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use OpenApi\Attributes as OA;
use Throwable;

/**
 * Password recovery on Laravel's native broker (config/auth.php
 * `passwords.users`: table password_reset_tokens, token stored as a bcrypt
 * hash, 60 min expiry, 60 s per-user throttle, deleted on use).
 *
 * - forgot: always the same 200 body, whether the account exists, is
 *   suspended, was throttled or the e-mail could not be queued — the public
 *   answer never reveals an account.
 * - preview: tells whether an e-mail+token pair is still usable, without
 *   consuming it.
 * - reset: explicit confirmation; sets the password, rotates remember_token,
 *   deletes the account's server-side sessions (same mechanism as platform
 *   suspension) and the broker deletes every reset token of the account.
 *   It never signs anyone in, never grants or changes organization access
 *   (a pending invitation still needs to be accepted), and never lifts a
 *   suspension: login keeps refusing suspended accounts.
 */
class PasswordResetController extends Controller
{
    private const GENERIC_SENT = 'If an account exists for this e-mail, we have sent a link to reset the password.';

    public function __construct(private readonly AuditLogger $auditLogger) {}

    #[OA\Post(
        path: '/api/v1/auth/forgot-password',
        operationId: 'authForgotPassword',
        summary: 'Request a password reset link (never reveals whether the account exists)',
        tags: ['Authentication'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['email'],
            properties: [
                new OA\Property(property: 'email', type: 'string', format: 'email', example: 'person@example.com'),
                new OA\Property(property: 'locale', type: 'string', enum: ['es-ES', 'ca-ES-valencia', 'en-GB']),
            ]
        )),
        responses: [
            new OA\Response(response: 200, description: 'Always the same generic message', content: new OA\JsonContent(properties: [new OA\Property(property: 'message', type: 'string')])),
            new OA\Response(response: 422, description: 'Validation error (malformed e-mail)'),
            new OA\Response(response: 429, description: 'Too many requests'),
        ]
    )]
    public function forgot(ForgotPasswordRequest $request): JsonResponse
    {
        $email = $request->validated('email');
        $locale = $request->validated('locale') ?? RestaurantSettings::DEFAULT_LOCALE;
        $user = User::query()->whereRaw('lower(email) = ?', [$email])->first();

        // A suspended account gets nothing (a reset could not be used to log
        // in anyway) — but the same answer as everyone else.
        if ($user !== null && ! $user->isSuspended()) {
            try {
                $this->broker()->sendResetLink(
                    ['email' => $user->email],
                    fn (User $account, string $token) => $account->notify(new ResetPasswordNotification($token, $locale)),
                );
            } catch (Throwable $e) {
                report($e);
            }
        }

        return response()->json(['message' => self::GENERIC_SENT]);
    }

    #[OA\Post(
        path: '/api/v1/auth/reset-password/preview',
        operationId: 'authResetPasswordPreview',
        summary: 'Check a reset link without consuming it',
        tags: ['Authentication'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['email', 'token'],
            properties: [
                new OA\Property(property: 'email', type: 'string', format: 'email', example: 'person@example.com'),
                new OA\Property(property: 'token', type: 'string', example: 'token-from-the-e-mail-link'),
            ]
        )),
        responses: [
            new OA\Response(response: 200, description: 'Usable', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', properties: [new OA\Property(property: 'valid', type: 'boolean', example: true)], type: 'object')])),
            new OA\Response(response: 422, description: 'PASSWORD_RESET_INVALID (invalid, expired or already used — indistinguishable on purpose)'),
            new OA\Response(response: 429, description: 'Too many requests'),
        ]
    )]
    public function preview(ResetPasswordRequest $request): JsonResponse
    {
        $user = User::query()->whereRaw('lower(email) = ?', [$request->validated('email')])->first();

        if ($user === null || ! $this->broker()->tokenExists($user, $request->validated('token'))) {
            return $this->invalid();
        }

        return response()->json(['data' => ['valid' => true]]);
    }

    #[OA\Post(
        path: '/api/v1/auth/reset-password',
        operationId: 'authResetPassword',
        summary: 'Set a new password with a valid reset link (does not sign in)',
        tags: ['Authentication'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['email', 'token', 'password', 'password_confirmation'],
            properties: [
                new OA\Property(property: 'email', type: 'string', format: 'email', example: 'person@example.com'),
                new OA\Property(property: 'token', type: 'string', example: 'token-from-the-e-mail-link'),
                new OA\Property(property: 'password', type: 'string', format: 'password', minLength: 8),
                new OA\Property(property: 'password_confirmation', type: 'string', format: 'password'),
            ]
        )),
        responses: [
            new OA\Response(response: 200, description: 'Password changed; every reset token and server-side session of the account revoked'),
            new OA\Response(response: 422, description: 'Validation error or PASSWORD_RESET_INVALID'),
            new OA\Response(response: 429, description: 'Too many requests'),
        ]
    )]
    public function reset(ResetPasswordRequest $request): JsonResponse
    {
        $status = $this->broker()->reset(
            [
                'email' => $request->validated('email'),
                'token' => $request->validated('token'),
                'password' => $request->validated('password'),
            ],
            function (User $user, #[\SensitiveParameter] string $password): void {
                DB::transaction(function () use ($user, $password): void {
                    $user->forceFill([
                        'password' => $password,
                        'remember_token' => Str::random(60),
                    ])->save();

                    if (config('session.driver') === 'database') {
                        DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
                    }

                    $this->auditLogger->log(
                        organizationId: null,
                        restaurantId: null,
                        actorType: AuditLog::ACTOR_USER,
                        actor: $user,
                        event: AuditLog::EVENT_AUTH_PASSWORD_RESET,
                        resourceType: AuditLog::RESOURCE_USER,
                        resourceId: $user->id,
                    );
                });

                event(new PasswordReset($user));
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            return $this->invalid();
        }

        return response()->json(['message' => 'Password changed. Sign in with the new password.']);
    }

    private function broker(): PasswordBroker
    {
        /** @var PasswordBroker */
        return Password::broker(config('auth.defaults.passwords'));
    }

    private function invalid(): JsonResponse
    {
        return response()->json([
            'error' => ['code' => 'PASSWORD_RESET_INVALID', 'message' => 'This password reset link is invalid or has expired. Ask for a new one.'],
        ], 422);
    }
}
