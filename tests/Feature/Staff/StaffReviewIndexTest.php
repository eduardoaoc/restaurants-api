<?php

namespace Tests\Feature\Staff;

use App\Actions\Staff\CreateStaffReviewAction;
use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class StaffReviewIndexTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    public function test_manager_can_list_reviews_of_a_staff_member(): void
    {
        [$organization, , $restaurant] = $this->createTenant();
        $manager = $this->createStaff($organization, $restaurant, 'manager', 'M-1');
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');

        app(CreateStaffReviewAction::class)->execute($organization, $restaurant, $waiter, $manager, 4, 'Good');
        app(CreateStaffReviewAction::class)->execute($organization, $restaurant, $waiter, $manager, 5, 'Great');

        $this->actingAs($manager, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/staff/{$waiter->id}/reviews")
            ->assertOk()
            ->assertJsonCount(2, 'data.reviews')
            ->assertJsonPath('data.reviews.0.rating', 5)
            ->assertJsonPath('data.reviews.0.comment', 'Great')
            ->assertJsonPath('data.reviews.0.reviewer.id', $manager->id)
            ->assertJsonPath('data.reviews.1.rating', 4);
    }

    public function test_reviews_are_ordered_newest_first_by_created_at_then_id(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');

        $this->travelTo(now()->subDays(2));
        $review1 = app(CreateStaffReviewAction::class)->execute($organization, $restaurant, $waiter, $owner, 1, 'first');
        $this->travelTo(now());
        $review2 = app(CreateStaffReviewAction::class)->execute($organization, $restaurant, $waiter, $owner, 2, 'second');
        $review3 = app(CreateStaffReviewAction::class)->execute($organization, $restaurant, $waiter, $owner, 3, 'third');

        $response = $this->actingAs($owner, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/staff/{$waiter->id}/reviews")
            ->assertOk();

        $ids = $response->json('data.reviews.*.id');
        $this->assertSame([$review3->id, $review2->id, $review1->id], $ids);
    }

    public function test_target_staff_without_manage_staff_reviews_permission_cannot_view_their_own_reviews(): void
    {
        [$organization, , $restaurant] = $this->createTenant();
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');

        $this->actingAs($waiter, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/staff/{$waiter->id}/reviews")
            ->assertForbidden();
    }

    public function test_staff_of_another_organization_is_not_found(): void
    {
        [$organization, $owner] = $this->createTenant();
        [$otherOrganization, , $otherRestaurant] = $this->createTenant();
        $otherStaff = $this->createStaff($otherOrganization, $otherRestaurant, 'waiter', 'W-1');

        $this->actingAs($owner, 'web')
            ->getJson("/api/v1/restaurants/{$otherRestaurant->id}/staff/{$otherStaff->id}/reviews")
            ->assertNotFound();
    }

    public function test_manager_scoped_to_one_restaurant_cannot_list_reviews_of_another_restaurant(): void
    {
        [$organization, , $restaurantA] = $this->createTenant();
        $restaurantB = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $manager = $this->createStaff($organization, $restaurantA, 'manager', 'M-A');
        $waiterB = $this->createStaff($organization, $restaurantB, 'waiter', 'W-B');

        $this->actingAs($manager, 'web')
            ->getJson("/api/v1/restaurants/{$restaurantB->id}/staff/{$waiterB->id}/reviews")
            ->assertNotFound();
    }

    /**
     * Rating isolation across restaurants (section 111/38): a review
     * created for restaurant A must never appear when listing reviews of
     * the same staff member through restaurant B.
     */
    public function test_reviews_of_a_staff_member_in_two_restaurants_are_isolated_per_restaurant(): void
    {
        [$organization, $owner, $restaurantA] = $this->createTenant();
        $restaurantB = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $carlos = $this->createStaffAcrossRestaurants($organization, [$restaurantA, $restaurantB], 'waiter', $owner);

        app(CreateStaffReviewAction::class)->execute($organization, $restaurantA, $carlos, $owner, 5, 'A review');
        app(CreateStaffReviewAction::class)->execute($organization, $restaurantB, $carlos, $owner, 1, 'B review');

        $this->actingAs($owner, 'web')
            ->getJson("/api/v1/restaurants/{$restaurantA->id}/staff/{$carlos->id}/reviews")
            ->assertOk()
            ->assertJsonCount(1, 'data.reviews')
            ->assertJsonPath('data.reviews.0.rating', 5);

        $this->actingAs($owner, 'web')
            ->getJson("/api/v1/restaurants/{$restaurantB->id}/staff/{$carlos->id}/reviews")
            ->assertOk()
            ->assertJsonCount(1, 'data.reviews')
            ->assertJsonPath('data.reviews.0.rating', 1);
    }
}
