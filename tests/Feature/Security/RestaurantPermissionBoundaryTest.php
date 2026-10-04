<?php

namespace Tests\Feature\Security;

use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * SEC-001 regressions enforce the restaurant-specific authorization boundary.
 */
class RestaurantPermissionBoundaryTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    public function test_manager_grant_at_a1_must_not_allow_settings_write_as_waiter_at_a2(): void
    {
        [$organization, , $a1] = $this->createTenant();
        $a2 = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $user = $this->createStaff($organization, $a1, 'manager', 'M-SEC');
        $a2->users()->attach($user->id, ['sub_id' => 'W-SEC']);
        $this->assignRole($user, 'waiter', $organization, $a2);

        $context = $this->actingAs($user, 'web')->getJson('/api/v1/auth/context')->assertOk();
        $restaurants = collect($context->json('data.organizations.0.restaurants'))->keyBy('id');
        $this->assertNotContains('manage_restaurants', $restaurants[$a2->id]['permissions']);
        $original = $a2->settings()->firstOrFail()->customer_ordering_enabled;

        $response = $this->patchJson("/api/v1/restaurants/{$a2->id}/settings", [
            'customer_ordering_enabled' => ! $original,
        ]);
        $this->assertSame($original, $a2->settings()->firstOrFail()->customer_ordering_enabled,
            'A manager grant at A1 allowed an unauthorized settings write at A2.');
        $response->assertForbidden();
    }

    public function test_manager_grant_at_a1_must_not_allow_history_read_as_waiter_at_a2(): void
    {
        [$organization, , $a1] = $this->createTenant();
        $a2 = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $user = $this->createStaff($organization, $a1, 'manager', 'M-SEC');
        $a2->users()->attach($user->id, ['sub_id' => 'W-SEC']);
        $this->assignRole($user, 'waiter', $organization, $a2);

        $context = $this->actingAs($user, 'web')->getJson('/api/v1/auth/context')->assertOk();
        $restaurants = collect($context->json('data.organizations.0.restaurants'))->keyBy('id');
        $this->assertNotContains('view_daily_closes', $restaurants[$a2->id]['permissions']);
        $this->getJson("/api/v1/restaurants/{$a2->id}/day-closes")->assertForbidden();
    }

    public function test_manager_grant_at_a1_must_not_allow_activity_channel_as_waiter_at_a2(): void
    {
        [$organization, , $a1] = $this->createTenant();
        $a2 = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $user = $this->createStaff($organization, $a1, 'manager', 'M-SEC');
        $a2->users()->attach($user->id, ['sub_id' => 'W-SEC']);
        $this->assignRole($user, 'waiter', $organization, $a2);

        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'security-test-key',
            'broadcasting.connections.reverb.secret' => 'security-test-secret',
            'broadcasting.connections.reverb.app_id' => 'security-test-app-id',
        ]);
        require base_path('routes/channels.php');

        $this->actingAs($user, 'web')->postJson('/broadcasting/auth', [
            'channel_name' => "private-restaurant.{$a2->id}.activity",
            'socket_id' => '1234.5678',
        ])->assertForbidden();
    }
}
