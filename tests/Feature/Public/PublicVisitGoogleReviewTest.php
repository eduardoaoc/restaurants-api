<?php

namespace Tests\Feature\Public;

use App\Actions\Tables\CloseTableAction;
use App\Models\Order;
use App\Models\Restaurant;
use App\Models\TableSession;
use App\Models\User;
use App\Support\Billing\SessionBillCalculator;
use App\Support\Money\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithCustomerFeedback;
use Tests\Concerns\InteractsWithOrders;
use Tests\Concerns\InteractsWithPayments;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * GET /public/visits/{feedbackToken} → google_review (CARTA 5.3A). Derived
 * only from the restaurant's settings.google_review_url: independent of
 * the session being open/closed and of feedback (submitted or not, any
 * rating) — i.e. no review gating.
 */
class PublicVisitGoogleReviewTest extends TestCase
{
    use InteractsWithCustomerFeedback, InteractsWithOrders, InteractsWithPayments, InteractsWithTenants, RefreshDatabase;

    private const URL = 'https://g.page/r/CabcdEFGhij123XYZ/review';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRolesAndPermissions();
    }

    /**
     * @return array{0: TableSession, 1: User, 2: Restaurant}
     */
    private function paidSession(?string $googleReviewUrl = null): array
    {
        [, $owner, $restaurant, $rp] = $this->createTenantWithRestaurantProduct();
        $restaurant->settings()->update(['google_review_url' => $googleReviewUrl]);
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $owner);

        $order = $this->createWaiterOrder($table, $owner, [['restaurant_product_id' => $rp->id, 'quantity' => 1]]);
        $this->advanceOrderTo($order, Order::STATUS_SERVED, $owner);

        $balance = SessionBillCalculator::summarize($session->refresh())['balanceCents'];
        $this->recordPayment($session, $owner, Money::centsToDecimal($balance));

        return [$session->refresh(), $owner, $restaurant];
    }

    /**
     * @return array<string, mixed>
     */
    private function googleReview(TableSession $session): array
    {
        return $this->getJson("/api/v1/public/visits/{$session->feedback_token}")
            ->assertOk()
            ->json('data.google_review');
    }

    public function test_a_unconfigured_restaurant_is_unavailable(): void
    {
        [$session] = $this->paidSession();

        $this->assertSame(['available' => false, 'url' => null], $this->googleReview($session));
    }

    public function test_b_configured_url_is_returned_exactly(): void
    {
        [$session] = $this->paidSession(self::URL);

        $this->assertSame(['available' => true, 'url' => self::URL], $this->googleReview($session));
    }

    public function test_c_paid_active_session(): void
    {
        [$session] = $this->paidSession(self::URL);
        $this->assertTrue($session->isActive());

        $this->assertSame(['available' => true, 'url' => self::URL], $this->googleReview($session));
    }

    public function test_d_paid_closed_session(): void
    {
        [$session, $owner] = $this->paidSession(self::URL);
        app(CloseTableAction::class)->execute($session, $owner);
        $this->assertFalse($session->refresh()->isActive());

        $this->assertSame(['available' => true, 'url' => self::URL], $this->googleReview($session));
    }

    public function test_e_available_before_any_feedback(): void
    {
        [$session] = $this->paidSession(self::URL);
        $this->assertFalse($session->hasSubmittedFeedback());

        $this->assertSame(['available' => true, 'url' => self::URL], $this->googleReview($session));
    }

    /**
     * @return array<string, array{0: int}>
     */
    public static function overallRatings(): array
    {
        return ['rating 1' => [1], 'rating 3' => [3], 'rating 5' => [5]];
    }

    #[DataProvider('overallRatings')]
    public function test_f_same_result_after_feedback_whatever_the_rating(int $rating): void
    {
        // No review gating: a 1-star AFORO review gets the same Google link as a 5-star one.
        [$session] = $this->paidSession(self::URL);
        $before = $this->googleReview($session);

        $this->submitFeedback($session, [
            'wait_time_rating' => $rating,
            'food_rating' => $rating,
            'service_rating' => $rating,
            'overall_rating' => $rating,
        ]);
        $this->assertTrue($session->hasSubmittedFeedback());

        $this->assertSame($before, $this->googleReview($session));
        $this->assertSame(['available' => true, 'url' => self::URL], $before);
    }

    public function test_changes_to_the_setting_are_reflected_on_the_visit(): void
    {
        [$session, , $restaurant] = $this->paidSession(self::URL);

        $restaurant->settings()->update(['google_review_url' => null]);

        $this->assertSame(['available' => false, 'url' => null], $this->googleReview($session));
    }

    public function test_only_available_and_url_are_exposed(): void
    {
        [$session] = $this->paidSession(self::URL);

        $response = $this->getJson("/api/v1/public/visits/{$session->feedback_token}")->assertOk();

        $this->assertSame(['available', 'url'], array_keys($response->json('data.google_review')));
        $this->assertEqualsCanonicalizing(
            ['restaurant', 'table', 'visit', 'orders', 'summary', 'google_review'],
            array_keys($response->json('data')),
        );
        $this->assertSame(['name'], array_keys($response->json('data.restaurant')));
        $this->assertStringNotContainsString('google_review_url', $response->getContent());
        $this->assertStringNotContainsString('settings', $response->getContent());
    }

    public function test_unpaid_visit_is_still_rejected(): void
    {
        [, $owner, $restaurant] = $this->createTenantWithRestaurantProduct();
        $restaurant->settings()->update(['google_review_url' => self::URL]);
        $session = $this->openSession($this->createTable($restaurant), $owner);

        $this->getJson("/api/v1/public/visits/{$session->feedback_token}")
            ->assertStatus(409)
            ->assertJson(['error' => ['code' => 'TABLE_SESSION_NOT_PAID_FOR_VISIT']]);
    }

    public function test_the_public_menu_and_table_resolution_do_not_expose_it(): void
    {
        [$session] = $this->paidSession(self::URL);
        $token = $session->table->public_token;
        $this->createMenu($session->restaurant);

        foreach (["/api/v1/public/tables/{$token}", "/api/v1/public/tables/{$token}/menu"] as $url) {
            $body = $this->getJson($url)->assertOk()->getContent();
            $this->assertStringNotContainsString('google_review', $body);
            $this->assertStringNotContainsString('g.page', $body);
        }
    }
}
