<?php

namespace Tests\Feature\CustomerFeedback;

use App\Actions\Tables\AssignWaiterAction;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Restaurant;
use App\Models\RestaurantProduct;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithCustomerFeedback;
use Tests\Concerns\InteractsWithOrders;
use Tests\Concerns\InteractsWithPayments;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class CustomerFeedbackSummaryTest extends TestCase
{
    use InteractsWithCustomerFeedback, InteractsWithOrders, InteractsWithPayments, InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
    }

    /**
     * @return array{0: Organization, 1: User, 2: Restaurant, 3: RestaurantProduct}
     */
    private function tenant(): array
    {
        return $this->createTenantWithRestaurantProduct();
    }

    private function submitFeedbackForWaiter(User $owner, Restaurant $restaurant, RestaurantProduct $rp, User $waiter, array $overrides = []): void
    {
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);
        app(AssignWaiterAction::class)->execute($session, $waiter, $owner);
        $order = $this->createWaiterOrder($table, $owner, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);
        $order = $this->advanceOrderTo($order, Order::STATUS_SERVED, $owner);
        $this->recordPayment($session, $owner, $order->total);

        $this->submitFeedback($session->refresh(), $overrides);
    }

    public function test_waiter_sees_own_aggregate_summary(): void
    {
        [$organization, $owner, $restaurant, $rp] = $this->tenant();
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'w1');

        $this->submitFeedbackForWaiter($owner, $restaurant, $rp, $waiter, ['overall_rating' => 5, 'service_rating' => 4]);
        $this->submitFeedbackForWaiter($owner, $restaurant, $rp, $waiter, ['overall_rating' => 3, 'service_rating' => 2]);

        $this->actingAs($waiter, 'web')
            ->getJson('/api/v1/me/feedback-summary')
            ->assertOk()
            ->assertJson([
                'data' => [
                    'feedback_count' => 2,
                    'average_overall' => 4.0,
                    'average_service' => 3.0,
                ],
            ]);
    }

    public function test_waiter_with_no_feedback_gets_zero_count_and_null_averages(): void
    {
        [$organization, , $restaurant] = $this->tenant();
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'w1');

        $this->actingAs($waiter, 'web')
            ->getJson('/api/v1/me/feedback-summary')
            ->assertOk()
            ->assertJson(['data' => ['feedback_count' => 0, 'average_overall' => null]]);
    }

    public function test_summary_never_exposes_pii_or_individual_feedback(): void
    {
        [$organization, $owner, $restaurant, $rp] = $this->tenant();
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'w1');
        $this->submitFeedbackForWaiter($owner, $restaurant, $rp, $waiter);

        $response = $this->actingAs($waiter, 'web')->getJson('/api/v1/me/feedback-summary')->assertOk();

        $response->assertJsonMissingPath('data.feedback');
        $response->assertJsonMissingPath('data.first_name');
        $response->assertJsonMissingPath('data.contact');
        $response->assertExactJson([
            'data' => [
                'feedback_count' => 1,
                'average_overall' => 5.0,
                'average_service' => 5.0,
                'average_wait_time' => 4.0,
                'average_food' => 5.0,
            ],
        ]);
    }

    public function test_one_waiters_feedback_never_counts_toward_another_waiters_summary(): void
    {
        [$organization, $owner, $restaurant, $rp] = $this->tenant();
        $waiterA = $this->createStaff($organization, $restaurant, 'waiter', 'w1');
        $waiterB = $this->createStaff($organization, $restaurant, 'waiter', 'w2');
        $this->submitFeedbackForWaiter($owner, $restaurant, $rp, $waiterA);

        $this->actingAs($waiterB, 'web')
            ->getJson('/api/v1/me/feedback-summary')
            ->assertOk()
            ->assertJson(['data' => ['feedback_count' => 0]]);
    }

    // --- Owner/manager viewing a specific staff member's summary -----------

    public function test_owner_can_view_a_waiters_feedback_summary(): void
    {
        [$organization, $owner, $restaurant, $rp] = $this->tenant();
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'w1');
        $this->submitFeedbackForWaiter($owner, $restaurant, $rp, $waiter);

        $this->actingAs($owner, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/staff/{$waiter->id}/feedback-summary")
            ->assertOk()
            ->assertJson(['data' => ['feedback_count' => 1]]);
    }

    public function test_waiter_cannot_view_another_staff_members_summary_via_the_staff_endpoint(): void
    {
        [$organization, $owner, $restaurant, $rp] = $this->tenant();
        $waiterA = $this->createStaff($organization, $restaurant, 'waiter', 'w1');
        $waiterB = $this->createStaff($organization, $restaurant, 'waiter', 'w2');
        $this->submitFeedbackForWaiter($owner, $restaurant, $rp, $waiterB);

        $this->actingAs($waiterA, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/staff/{$waiterB->id}/feedback-summary")
            ->assertStatus(403);
    }

    public function test_kitchen_cannot_view_a_waiters_summary(): void
    {
        [$organization, $owner, $restaurant, $rp] = $this->tenant();
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'w1');
        $kitchen = $this->createStaff($organization, $restaurant, 'kitchen', 'k1');
        $this->submitFeedbackForWaiter($owner, $restaurant, $rp, $waiter);

        $this->actingAs($kitchen, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/staff/{$waiter->id}/feedback-summary")
            ->assertStatus(403);
    }

    // --- RestaurantScope -----------------------------------------------------

    public function test_manager_of_a_sibling_restaurant_cannot_view_the_waiters_summary(): void
    {
        [$organization, $owner, $restaurant, $rp] = $this->tenant();
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'w1');
        $this->submitFeedbackForWaiter($owner, $restaurant, $rp, $waiter);

        $otherRestaurant = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $manager = $this->createStaff($organization, $otherRestaurant, 'manager', 'm1');

        $this->actingAs($manager, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/staff/{$waiter->id}/feedback-summary")
            ->assertStatus(404);
    }

    public function test_owner_viewing_own_summary_only_counts_restaurants_in_their_scope(): void
    {
        // An org-wide owner is never a candidate waiter (WaiterAssignmentEligibility
        // requires the `waiter` role specifically), so their own feedback
        // summary is always zero — this only proves RestaurantScope's
        // null ("every restaurant") branch doesn't blow up the query.
        [, $owner] = $this->tenant();

        $this->actingAs($owner, 'web')
            ->getJson('/api/v1/me/feedback-summary')
            ->assertOk()
            ->assertJson(['data' => ['feedback_count' => 0]]);
    }
}
