<?php

namespace Tests\Feature\Staff;

use App\Actions\Staff\CreateStaffReviewAction;
use App\Models\Organization;
use App\Models\Restaurant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithOrders;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class StaffPerformanceMeTest extends TestCase
{
    use InteractsWithOrders, InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    public function test_operational_staff_sees_their_own_restaurant_scope(): void
    {
        [$organization, , $restaurant] = $this->createTenant();
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');

        $this->actingAs($waiter, 'web')
            ->getJson('/api/v1/me/performance')
            ->assertOk()
            ->assertJsonPath('data.performance.scope', 'restaurant')
            ->assertJsonPath('data.performance.staff.id', $waiter->id)
            ->assertJsonPath('data.performance.staff.restaurant.id', $restaurant->id);
    }

    public function test_organization_wide_owner_sees_organization_scope(): void
    {
        [$organization, $owner] = $this->createTenant();

        $this->actingAs($owner, 'web')
            ->getJson('/api/v1/me/performance')
            ->assertOk()
            ->assertJsonPath('data.performance.scope', 'organization')
            ->assertJsonPath('data.performance.staff.restaurant', null);
    }

    public function test_owner_self_aggregates_actions_across_own_restaurants_but_not_other_organizations(): void
    {
        $organization = Organization::factory()->create();
        $owner = User::factory()->create();
        $this->assignRole($owner, 'owner', $organization);
        $restaurantA = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $restaurantB = Restaurant::factory()->create(['organization_id' => $organization->id]);

        $otherOrganization = Organization::factory()->create();
        $this->assignRole($owner, 'owner', $otherOrganization);
        $otherRestaurant = Restaurant::factory()->create(['organization_id' => $otherOrganization->id]);

        $tableA = $this->createTable($restaurantA);
        $tableB = $this->createTable($restaurantB);
        $otherTable = $this->createTable($otherRestaurant);
        $this->openSession($tableA, $owner);
        $this->openSession($tableB, $owner);
        $this->openSession($otherTable, $owner);

        $restaurantProductA = $this->createRestaurantProduct($restaurantA, $this->createProduct($organization));
        $restaurantProductB = $this->createRestaurantProduct($restaurantB, $this->createProduct($organization));
        $otherRestaurantProduct = $this->createRestaurantProduct($otherRestaurant, $this->createProduct($otherOrganization));

        $this->createWaiterOrder($tableA, $owner, [
            ['restaurant_product_id' => $restaurantProductA->id, 'quantity' => 1],
        ]);
        $this->createWaiterOrder($tableB, $owner, [
            ['restaurant_product_id' => $restaurantProductB->id, 'quantity' => 1],
        ]);
        $this->createWaiterOrder($otherTable, $owner, [
            ['restaurant_product_id' => $otherRestaurantProduct->id, 'quantity' => 1],
        ]);

        // TenantContext resolves the active organization as the user's
        // first organization; assert it is $organization for this test to
        // be meaningful.
        $this->assertSame($organization->id, $owner->organizations()->first()->id);

        $this->actingAs($owner, 'web')
            ->getJson('/api/v1/me/performance')
            ->assertOk()
            ->assertJsonPath('data.performance.scope', 'organization')
            ->assertJsonPath('data.performance.metrics.orders_created', 2);
    }

    public function test_no_permission_required_beyond_authentication(): void
    {
        [$organization, , $restaurant] = $this->createTenant();
        // kitchen has no view_reports / manage_staff_reviews permission at all.
        $kitchen = $this->createStaff($organization, $restaurant, 'kitchen', 'K-1');

        $this->actingAs($kitchen, 'web')
            ->getJson('/api/v1/me/performance')
            ->assertOk();
    }

    public function test_response_never_exposes_review_comments_reviewer_or_review_list(): void
    {
        [$organization, , $restaurant] = $this->createTenant();
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');
        $manager = $this->createStaff($organization, $restaurant, 'manager', 'M-1');

        app(CreateStaffReviewAction::class)->execute($organization, $restaurant, $waiter, $manager, 4, 'Secret internal note');

        $response = $this->actingAs($waiter, 'web')
            ->getJson('/api/v1/me/performance')
            ->assertOk()
            ->assertJsonPath('data.performance.rating.average', '4.00')
            ->assertJsonPath('data.performance.rating.review_count', 1);

        $response->assertJsonMissingPath('data.performance.rating.comment');
        $response->assertJsonMissingPath('data.performance.rating.reviewer');
        $response->assertJsonMissingPath('data.performance.rating.reviews');
        $response->assertJsonMissingPath('data.performance.reviews');
        $this->assertStringNotContainsString('Secret internal note', $response->getContent());
    }

    public function test_rating_average_is_null_when_there_are_no_reviews(): void
    {
        [$organization, , $restaurant] = $this->createTenant();
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');

        $this->actingAs($waiter, 'web')
            ->getJson('/api/v1/me/performance')
            ->assertOk()
            ->assertJsonPath('data.performance.rating.average', null)
            ->assertJsonPath('data.performance.rating.review_count', 0);
    }

    public function test_default_period_is_the_current_calendar_month(): void
    {
        $this->travelTo(CarbonImmutable::create(2026, 9, 15, 12, 0, 0));

        [$organization, , $restaurant] = $this->createTenant();
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');

        $this->actingAs($waiter, 'web')
            ->getJson('/api/v1/me/performance')
            ->assertOk()
            ->assertJsonPath('data.performance.period.from', '2026-09-01')
            ->assertJsonPath('data.performance.period.to', '2026-09-30');
    }

    public function test_partial_period_is_rejected(): void
    {
        [$organization, , $restaurant] = $this->createTenant();
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');

        $this->actingAs($waiter, 'web')
            ->getJson('/api/v1/me/performance?from=2026-09-01')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'INVALID_PERFORMANCE_PERIOD');
    }

    public function test_period_end_before_start_is_rejected(): void
    {
        [$organization, , $restaurant] = $this->createTenant();
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');

        $this->actingAs($waiter, 'web')
            ->getJson('/api/v1/me/performance?from=2026-09-10&to=2026-09-01')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'INVALID_PERFORMANCE_PERIOD');
    }

    public function test_period_longer_than_366_days_is_rejected(): void
    {
        [$organization, , $restaurant] = $this->createTenant();
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');

        $this->actingAs($waiter, 'web')
            ->getJson('/api/v1/me/performance?from=2025-01-01&to=2026-01-02')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'INVALID_PERFORMANCE_PERIOD');
    }

    public function test_explicit_period_is_echoed_back(): void
    {
        [$organization, , $restaurant] = $this->createTenant();
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');

        $this->actingAs($waiter, 'web')
            ->getJson('/api/v1/me/performance?from=2026-01-01&to=2026-01-31')
            ->assertOk()
            ->assertJsonPath('data.performance.period.from', '2026-01-01')
            ->assertJsonPath('data.performance.period.to', '2026-01-31');
    }
}
