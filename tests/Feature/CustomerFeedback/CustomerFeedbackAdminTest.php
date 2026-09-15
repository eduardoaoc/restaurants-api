<?php

namespace Tests\Feature\CustomerFeedback;

use App\Models\CustomerFeedback;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Restaurant;
use App\Models\RestaurantProduct;
use App\Models\Table;
use App\Models\TableSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithCustomerFeedback;
use Tests\Concerns\InteractsWithOrders;
use Tests\Concerns\InteractsWithPayments;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class CustomerFeedbackAdminTest extends TestCase
{
    use InteractsWithCustomerFeedback, InteractsWithOrders, InteractsWithPayments, InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
    }

    /**
     * @return array{0: Organization, 1: User, 2: Restaurant, 3: RestaurantProduct, 4: Table, 5: TableSession, 6: CustomerFeedback}
     */
    private function restaurantWithSubmittedFeedback(): array
    {
        [$organization, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);
        $order = $this->createWaiterOrder($table, $owner, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);
        $order = $this->advanceOrderTo($order, Order::STATUS_SERVED, $owner);
        $this->recordPayment($session, $owner, $order->total);

        ['feedback' => $feedback] = $this->submitFeedback($session->refresh());

        return [$organization, $owner, $restaurant, $rp, $table, $session, $feedback];
    }

    // --- Owner / Manager: allowed -----------------------------------------

    public function test_owner_can_list_feedback(): void
    {
        [, $owner, $restaurant] = $this->restaurantWithSubmittedFeedback();

        $this->actingAs($owner, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/feedback")
            ->assertOk()
            ->assertJsonPath('data.feedback.0.overall_rating', 5)
            ->assertJsonPath('data.feedback.0.customer_name', 'Ana García')
            ->assertJsonStructure(['data' => ['feedback'], 'meta' => ['current_page', 'per_page', 'total', 'last_page']]);
    }

    public function test_list_never_includes_comments_or_contact(): void
    {
        [, $owner, $restaurant] = $this->restaurantWithSubmittedFeedback();

        $response = $this->actingAs($owner, 'web')->getJson("/api/v1/restaurants/{$restaurant->id}/feedback")->assertOk();

        $response->assertJsonMissingPath('data.feedback.0.contact');
        $response->assertJsonMissingPath('data.feedback.0.experience_comment');
    }

    public function test_owner_can_view_feedback_detail(): void
    {
        [, $owner, , , , , $feedback] = $this->restaurantWithSubmittedFeedback();

        $this->actingAs($owner, 'web')
            ->getJson("/api/v1/feedback/{$feedback->id}")
            ->assertOk()
            ->assertJson([
                'data' => [
                    'first_name' => 'Ana',
                    'last_name' => 'García',
                    'contact' => 'ana@example.com',
                    'experience_comment' => 'Great evening.',
                    'improvement_comment' => 'Faster drinks next time.',
                ],
            ]);
    }

    public function test_manager_can_view_feedback_detail(): void
    {
        [$organization, , $restaurant, , , , $feedback] = $this->restaurantWithSubmittedFeedback();
        $manager = $this->createStaff($organization, $restaurant, 'manager', 'm1');

        $this->actingAs($manager, 'web')
            ->getJson("/api/v1/feedback/{$feedback->id}")
            ->assertOk()
            ->assertJson(['data' => ['first_name' => 'Ana']]);
    }

    public function test_manager_can_list_feedback(): void
    {
        [$organization, , $restaurant] = $this->restaurantWithSubmittedFeedback();
        $manager = $this->createStaff($organization, $restaurant, 'manager', 'm1');

        $this->actingAs($manager, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/feedback")
            ->assertOk()
            ->assertJsonCount(1, 'data.feedback');
    }

    // --- Negative permissions ----------------------------------------------

    public function test_waiter_cannot_view_feedback_detail(): void
    {
        [$organization, , $restaurant, , , , $feedback] = $this->restaurantWithSubmittedFeedback();
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'w1');

        $this->actingAs($waiter, 'web')
            ->getJson("/api/v1/feedback/{$feedback->id}")
            ->assertStatus(403);
    }

    public function test_waiter_cannot_list_feedback(): void
    {
        [$organization, , $restaurant] = $this->restaurantWithSubmittedFeedback();
        $waiter = $this->createStaff($organization, $restaurant, 'waiter', 'w1');

        $this->actingAs($waiter, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/feedback")
            ->assertStatus(403);
    }

    public function test_kitchen_cannot_view_feedback_detail(): void
    {
        [$organization, , $restaurant, , , , $feedback] = $this->restaurantWithSubmittedFeedback();
        $kitchen = $this->createStaff($organization, $restaurant, 'kitchen', 'k1');

        $this->actingAs($kitchen, 'web')
            ->getJson("/api/v1/feedback/{$feedback->id}")
            ->assertStatus(403);
    }

    public function test_kitchen_cannot_list_feedback(): void
    {
        [$organization, , $restaurant] = $this->restaurantWithSubmittedFeedback();
        $kitchen = $this->createStaff($organization, $restaurant, 'kitchen', 'k1');

        $this->actingAs($kitchen, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/feedback")
            ->assertStatus(403);
    }

    public function test_cashier_cannot_view_feedback_detail_without_the_permission(): void
    {
        [$organization, , $restaurant, , , , $feedback] = $this->restaurantWithSubmittedFeedback();
        $cashier = $this->createStaff($organization, $restaurant, 'cashier', 'c1');

        $this->actingAs($cashier, 'web')
            ->getJson("/api/v1/feedback/{$feedback->id}")
            ->assertStatus(403);
    }

    public function test_unauthenticated_request_cannot_list_feedback(): void
    {
        [, , $restaurant] = $this->restaurantWithSubmittedFeedback();

        $this->getJson("/api/v1/restaurants/{$restaurant->id}/feedback")->assertStatus(401);
    }

    public function test_unauthenticated_request_cannot_view_feedback_detail(): void
    {
        [, , , , , , $feedback] = $this->restaurantWithSubmittedFeedback();

        $this->getJson("/api/v1/feedback/{$feedback->id}")->assertStatus(401);
    }

    // --- RestaurantScope -----------------------------------------------------

    public function test_staff_of_a_sibling_restaurant_in_the_same_organization_cannot_view_detail(): void
    {
        [$organization, $owner, , , , , $feedback] = $this->restaurantWithSubmittedFeedback();
        $otherRestaurant = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $manager = $this->createStaff($organization, $otherRestaurant, 'manager', 'm1');

        $this->actingAs($manager, 'web')
            ->getJson("/api/v1/feedback/{$feedback->id}")
            ->assertStatus(404);
    }

    public function test_staff_of_a_sibling_restaurant_cannot_list_its_feedback(): void
    {
        [$organization, , $restaurant] = $this->restaurantWithSubmittedFeedback();
        $otherRestaurant = Restaurant::factory()->create(['organization_id' => $organization->id]);
        $manager = $this->createStaff($organization, $otherRestaurant, 'manager', 'm1');

        $this->actingAs($manager, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/feedback")
            ->assertStatus(404);
    }

    public function test_owner_of_a_different_organization_cannot_view_the_feedback(): void
    {
        [, , , , , , $feedback] = $this->restaurantWithSubmittedFeedback();
        [, $otherOwner] = $this->createTenant();

        $this->actingAs($otherOwner, 'web')
            ->getJson("/api/v1/feedback/{$feedback->id}")
            ->assertStatus(404);
    }
}
