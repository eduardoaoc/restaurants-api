<?php

namespace Tests\Concerns;

use App\Models\Invitation;
use App\Models\User;
use App\Support\Invitations\InvitationTokens;
use Illuminate\Testing\TestResponse;

/**
 * Invitation fixtures. Tokens are obtained exactly the way the e-mail gets
 * them (InvitationTokens::mint), never by reading anything back from the
 * database — the database only ever has the hash.
 */
trait InteractsWithInvitations
{
    protected function mintToken(Invitation $invitation): string
    {
        return InvitationTokens::mint($invitation);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function newOrganizationPayload(array $overrides = []): array
    {
        return array_replace_recursive([
            'organization' => ['name' => 'Casa Pepe SL', 'slug' => 'casa-pepe'],
            'restaurant' => ['name' => 'Casa Pepe Ruzafa', 'slug' => 'casa-pepe-ruzafa'],
            'owner' => ['email' => 'Pepe@Example.com', 'name' => 'Pepe'],
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function onboard(User $admin, array $payload, string $key = 'onboard-key-1'): TestResponse
    {
        return $this->actingAs($admin, 'web')
            ->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/platform/restaurants', $payload);
    }

    /**
     * A different person/browser: no authenticated user, empty session.
     * (The test session store outlives requests; without this the previous
     * user's Sanctum password hash would still be in it.)
     */
    protected function freshBrowser(): void
    {
        $this->app['auth']->guard('web')->logout();
        $this->app['auth']->forgetGuards();
        $this->flushSession();
    }

    /** Next request of the same browser: guards re-resolve from the session. */
    protected function sameBrowserNextRequest(): void
    {
        $this->app['auth']->forgetGuards();
    }

    protected function acceptNew(string $token, string $name = 'Nueva Persona', string $password = 'secret-pass-123'): TestResponse
    {
        return $this->postJson('/api/v1/invitations/accept', [
            'token' => $token,
            'name' => $name,
            'password' => $password,
            'password_confirmation' => $password,
        ]);
    }

    protected function acceptExisting(string $token, string $password): TestResponse
    {
        return $this->postJson('/api/v1/invitations/accept-existing', ['token' => $token, 'password' => $password]);
    }
}
