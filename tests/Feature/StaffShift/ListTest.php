<?php

namespace Tests\Feature\StaffShift;

use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithStaffShifts;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bloco 3 — listing staff shifts: active-only filter, history, pagination,
 * and cross-restaurant isolation.
 */
class ListTest extends TestCase
{
    use InteractsWithStaffShifts, InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    public function test_active_filter_returns_only_active_shifts(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $mateo = $this->createStaff($organization, $restaurant, 'waiter', 'W-1', name: 'Mateo');
        $lucia = $this->createStaff($organization, $restaurant, 'waiter', 'W-2', name: 'Lucía');

        $activeShift = $this->startShift($restaurant, $mateo, $mateo);
        $endedShift = $this->startShift($restaurant, $lucia, $lucia);
        $this->endShift($endedShift, $lucia);

        $response = $this->actingAs($owner, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/staff-shifts?active=true")
            ->assertOk();

        $ids = collect($response->json('data.staff_shifts'))->pluck('id')->all();
        $this->assertSame([$activeShift->id], $ids);
    }

    public function test_history_includes_ended_shifts(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');
        $shift = $this->startShift($restaurant, $waiter, $waiter);
        $this->endShift($shift, $waiter);

        $response = $this->actingAs($owner, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/staff-shifts")
            ->assertOk();

        $ids = collect($response->json('data.staff_shifts'))->pluck('id')->all();
        $this->assertContains($shift->id, $ids);
    }

    public function test_restaurant_a_never_leaks_restaurant_b_shifts(): void
    {
        [$organization, $owner, $restaurantA] = $this->createTenant();
        $restaurantB = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $waiterA = $this->createStaff($organization, $restaurantA, 'waiter', 'W-A');
        $waiterB = $this->createStaff($organization, $restaurantB, 'waiter', 'W-B');

        $this->startShift($restaurantA, $waiterA, $waiterA);
        $this->startShift($restaurantB, $waiterB, $waiterB);

        $response = $this->actingAs($owner, 'web')
            ->getJson("/api/v1/restaurants/{$restaurantA->id}/staff-shifts")
            ->assertOk();

        $userIds = collect($response->json('data.staff_shifts'))->pluck('user.id')->all();
        $this->assertContains($waiterA->id, $userIds);
        $this->assertNotContains($waiterB->id, $userIds);
    }

    public function test_user_id_filter_narrows_results(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $mateo = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');
        $lucia = $this->createStaff($organization, $restaurant, 'waiter', 'W-2');
        $this->startShift($restaurant, $mateo, $mateo);
        $this->startShift($restaurant, $lucia, $lucia);

        $response = $this->actingAs($owner, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/staff-shifts?user_id={$mateo->id}")
            ->assertOk();

        $userIds = collect($response->json('data.staff_shifts'))->pluck('user.id')->all();
        $this->assertSame([$mateo->id], $userIds);
    }

    public function test_pagination_returns_meta_and_respects_per_page(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();

        foreach (range(1, 3) as $i) {
            $staff = $this->createStaff($organization, $restaurant, 'waiter', "W-{$i}");
            $shift = $this->startShift($restaurant, $staff, $staff);
            $this->endShift($shift, $staff);
        }

        $response = $this->actingAs($owner, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/staff-shifts?per_page=2")
            ->assertOk();

        $this->assertCount(2, $response->json('data.staff_shifts'));
        $this->assertSame(2, $response->json('meta.per_page'));
        $this->assertSame(3, $response->json('meta.total'));
        $this->assertSame(2, $response->json('meta.last_page'));
    }

    public function test_waiter_without_manage_staff_shifts_permission_cannot_list(): void
    {
        [$organization, , $restaurant] = $this->createTenant();
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');

        $this->actingAs($waiter, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/staff-shifts")
            ->assertForbidden();
    }

    public function test_invalid_period_returns_validation_error(): void
    {
        [, $owner, $restaurant] = $this->createTenant();

        $this->actingAs($owner, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/staff-shifts?from=2026-01-10&to=2026-01-01")
            ->assertStatus(422);
    }
}
