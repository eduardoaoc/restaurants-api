<?php

namespace Tests\Feature\Staff;

use App\Models\Restaurant;
use App\Models\StaffReview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class StaffReviewStoreTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    public function test_manager_can_create_a_review_with_expected_fields(): void
    {
        [$organization, , $restaurant] = $this->createTenant();
        $manager = $this->createStaff($organization, $restaurant, 'manager', 'M-1');
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');

        $response = $this->actingAs($manager, 'web')
            ->postJson("/api/v1/staff/{$waiter->id}/reviews", [
                'rating' => 5,
                'comment' => 'Great shift.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.review.rating', 5)
            ->assertJsonPath('data.review.comment', 'Great shift.')
            ->assertJsonPath('data.review.reviewer.id', $manager->id);

        $reviewId = $response->json('data.review.id');

        $this->assertDatabaseHas('staff_reviews', [
            'id' => $reviewId,
            'organization_id' => $organization->id,
            'restaurant_id' => $restaurant->id,
            'staff_user_id' => $waiter->id,
            'reviewer_user_id' => $manager->id,
            'rating' => 5,
            'comment' => 'Great shift.',
        ]);
    }

    public function test_owner_can_create_a_review(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');

        $this->actingAs($owner, 'web')
            ->postJson("/api/v1/staff/{$waiter->id}/reviews", ['rating' => 3])
            ->assertCreated();
    }

    public function test_client_supplied_restaurant_id_is_ignored_and_derived_server_side(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $otherRestaurant = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');

        $this->actingAs($owner, 'web')
            ->postJson("/api/v1/staff/{$waiter->id}/reviews", [
                'rating' => 4,
                'restaurant_id' => $otherRestaurant->id,
                'organization_id' => 999999,
                'staff_user_id' => 999999,
                'reviewer_user_id' => 999999,
            ])
            ->assertCreated();

        $review = StaffReview::query()->latest('id')->first();
        $this->assertSame($restaurant->id, $review->restaurant_id);
        $this->assertSame($organization->id, $review->organization_id);
        $this->assertSame($waiter->id, $review->staff_user_id);
        $this->assertSame($owner->id, $review->reviewer_user_id);
    }

    public function test_self_review_is_rejected(): void
    {
        [$organization, , $restaurant] = $this->createTenant();
        $manager = $this->createStaff($organization, $restaurant, 'manager', 'M-1');

        $this->actingAs($manager, 'web')
            ->postJson("/api/v1/staff/{$manager->id}/reviews", ['rating' => 5])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'CANNOT_REVIEW_SELF');
    }

    public function test_staff_without_manage_staff_reviews_permission_is_forbidden(): void
    {
        [$organization, , $restaurant] = $this->createTenant();
        $waiterA = $this->createStaff($organization, $restaurant, 'waiter', 'W-A');
        $waiterB = $this->createStaff($organization, $restaurant, 'waiter', 'W-B');

        $this->actingAs($waiterA, 'web')
            ->postJson("/api/v1/staff/{$waiterB->id}/reviews", ['rating' => 5])
            ->assertForbidden();
    }

    public function test_staff_of_another_organization_is_not_found(): void
    {
        [$organization, $owner] = $this->createTenant();
        [$otherOrganization, , $otherRestaurant] = $this->createTenant();
        $otherStaff = $this->createStaff($otherOrganization, $otherRestaurant, 'waiter', 'W-1');

        $this->actingAs($owner, 'web')
            ->postJson("/api/v1/staff/{$otherStaff->id}/reviews", ['rating' => 5])
            ->assertNotFound();
    }

    public function test_manager_scoped_to_one_restaurant_cannot_review_staff_of_another_restaurant(): void
    {
        [$organization, , $restaurantA] = $this->createTenant();
        $restaurantB = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $manager = $this->createStaff($organization, $restaurantA, 'manager', 'M-A');
        $waiterB = $this->createStaff($organization, $restaurantB, 'waiter', 'W-B');

        $this->actingAs($manager, 'web')
            ->postJson("/api/v1/staff/{$waiterB->id}/reviews", ['rating' => 5])
            ->assertNotFound();
    }

    #[DataProvider('invalidRatingProvider')]
    public function test_invalid_rating_is_rejected(mixed $rating): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');

        $this->actingAs($owner, 'web')
            ->postJson("/api/v1/staff/{$waiter->id}/reviews", ['rating' => $rating])
            ->assertStatus(422);
    }

    public static function invalidRatingProvider(): array
    {
        return [
            'zero' => [0],
            'six' => [6],
            'negative' => [-1],
            'string' => ['excellent'],
            'missing' => [null],
        ];
    }

    #[DataProvider('validRatingProvider')]
    public function test_valid_rating_boundaries_are_accepted(int $rating): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');

        $this->actingAs($owner, 'web')
            ->postJson("/api/v1/staff/{$waiter->id}/reviews", ['rating' => $rating])
            ->assertCreated();
    }

    public static function validRatingProvider(): array
    {
        return [
            'lower bound' => [1],
            'upper bound' => [5],
        ];
    }

    public function test_comment_over_max_length_is_rejected(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');

        $this->actingAs($owner, 'web')
            ->postJson("/api/v1/staff/{$waiter->id}/reviews", [
                'rating' => 5,
                'comment' => str_repeat('a', 1001),
            ])
            ->assertStatus(422);
    }

    public function test_comment_is_optional(): void
    {
        [$organization, $owner, $restaurant] = $this->createTenant();
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'W-1');

        $this->actingAs($owner, 'web')
            ->postJson("/api/v1/staff/{$waiter->id}/reviews", ['rating' => 3])
            ->assertCreated()
            ->assertJsonPath('data.review.comment', null);
    }
}
