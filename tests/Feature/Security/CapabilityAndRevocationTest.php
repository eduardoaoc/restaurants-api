<?php

namespace Tests\Feature\Security;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithOrders;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class CapabilityAndRevocationTest extends TestCase
{
    use InteractsWithOrders, InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
        config(['broadcasting.default' => 'reverb', 'broadcasting.connections.reverb.key' => 'test-key', 'broadcasting.connections.reverb.secret' => 'test-secret', 'broadcasting.connections.reverb.app_id' => 'test-app-id']);
        require base_path('routes/channels.php');
    }

    public static function roles(): array
    {
        return array_map(fn ($role) => [$role], ['owner', 'manager', 'waiter', 'cashier', 'kitchen']);
    }

    #[DataProvider('roles')]
    public function test_direct_api_calls_enforce_capabilities(string $role): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $actor = $role === 'owner' ? $owner : $this->createStaff($organization, $restaurant, $role, 'actor');
        $manager = in_array($role, ['owner', 'manager'], true);
        $this->actingAs($actor, 'web');
        foreach (["/restaurants/{$restaurant->id}/settings", '/staff', "/restaurants/{$restaurant->id}/activity", "/restaurants/{$restaurant->id}/feedback", "/restaurants/{$restaurant->id}/day-closes"] as $url) {
            $this->getJson('/api/v1'.$url)->assertStatus($manager ? 200 : 403);
        }
        $this->getJson("/api/v1/restaurants/{$restaurant->id}/day-close/preview")->assertStatus($role === 'kitchen' ? 403 : 200);
        $this->patchJson("/api/v1/restaurants/{$restaurant->id}/settings", ['customer_ordering_enabled' => false])->assertStatus($manager ? 200 : 403);
        $menu = $this->createMenu($restaurant);
        $category = $this->createCategory($menu);
        $this->patchJson("/api/v1/categories/{$category->id}", ['sort_order' => 2])->assertStatus($manager ? 200 : 403);
        $this->getJson('/api/v1/products')->assertStatus($role === 'owner' ? 200 : 403);
        $this->getJson('/api/v1/platform/users')->assertForbidden();
        $this->postJson('/broadcasting/auth', ['channel_name' => "private-restaurant.{$restaurant->id}.activity", 'socket_id' => '1.2'])->assertStatus($manager ? 200 : 403);
        $this->postJson('/broadcasting/auth', ['channel_name' => 'private-user.'.$actor->id, 'socket_id' => '1.2'])->assertOk();
        $other = User::factory()->create();
        $this->postJson('/broadcasting/auth', ['channel_name' => 'private-user.'.$other->id, 'socket_id' => '1.2'])->assertForbidden();
    }

    public static function revocations(): array
    {
        return array_map(fn ($kind) => [$kind], ['user', 'membership', 'membership_removed', 'restaurant', 'role', 'organization']);
    }

    #[DataProvider('revocations')]
    public function test_revocation_is_observed_by_existing_session_and_channels(string $kind): void
    {
        [$organization, , $restaurant] = $this->createTenant();
        $actor = $this->createStaff($organization, $restaurant, 'manager', 'actor');
        $this->postJson('/api/v1/auth/login', ['email' => $actor->email, 'password' => 'password123'])->assertOk();
        Auth::forgetGuards();
        $this->getJson("/api/v1/restaurants/{$restaurant->id}/activity")->assertOk();
        match ($kind) {
            'user' => $actor->update(['status' => User::STATUS_SUSPENDED]),
            'membership' => $organization->users()->updateExistingPivot($actor->id, ['status' => 'inactive']),
            'membership_removed' => $organization->users()->detach($actor->id),
            'restaurant' => $restaurant->users()->detach($actor->id),
            'role' => $actor->userRoles()->delete(),
            'organization' => $organization->update(['status' => Organization::STATUS_SUSPENDED]),
        };
        Auth::forgetGuards();
        $response = $this->getJson("/api/v1/restaurants/{$restaurant->id}/activity");
        $this->assertContains($response->status(), [403, 404]);
        Auth::forgetGuards();
        $this->postJson('/broadcasting/auth', ['channel_name' => "private-restaurant.{$restaurant->id}.activity", 'socket_id' => '1.2'])->assertForbidden();
        Auth::forgetGuards();
        $this->postJson('/broadcasting/auth', ['channel_name' => "private-restaurant.{$restaurant->id}", 'socket_id' => '1.2'])->assertStatus($kind === 'role' ? 200 : 403);
    }
}
