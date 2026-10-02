<?php

namespace Tests\Concerns;

use App\Actions\Tables\CloseTableAction;
use App\Models\CustomerFeedback;
use App\Models\Order;
use App\Models\Restaurant;
use App\Models\TableSession;
use App\Models\User;
use App\Support\Billing\SessionBillCalculator;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * Cierre Diario (CARTA 9.1A) test helpers. Assumes the test class also
 * uses InteractsWithTenants, InteractsWithOrders and InteractsWithPayments.
 */
trait InteractsWithDayClose
{
    /**
     * Sanctum's request guard caches the first authenticated user for the
     * whole test, and AuthenticateSession pins the session to the previous
     * user's password hash — forget both so each request really runs as
     * $user.
     */
    protected function as(User $user): static
    {
        Auth::forgetGuards();
        $this->flushSession();

        return $this->actingAs($user, 'web');
    }

    protected function at(string $utc): CarbonImmutable
    {
        $instant = CarbonImmutable::parse($utc, 'UTC');
        $this->travelTo($instant);

        return $instant;
    }

    /**
     * A full real service: table opened, one order served, paid in full
     * with $method and (by default) closed — all through the real actions.
     */
    protected function servedPaidSession(
        Restaurant $restaurant,
        User $actor,
        string $price = '10.00',
        string $method = 'cash',
        int $quantity = 1,
        ?string $productName = null,
        int $guests = 2,
        bool $close = true,
    ): TableSession {
        $table = $this->createTable($restaurant);
        $session = $this->openSession($table, $actor, $guests);
        $restaurantProduct = $this->createRestaurantProduct($restaurant, $this->createProduct($restaurant->organization, $productName), (float) $price);
        $order = $this->createWaiterOrder($table, $actor, [['restaurant_product_id' => $restaurantProduct->id, 'quantity' => $quantity]]);
        $this->advanceOrderTo($order, Order::STATUS_SERVED, $actor);

        $balanceCents = SessionBillCalculator::summarize($session->refresh())['balanceCents'];
        $this->recordPayment($session, $actor, Money::centsToDecimal($balanceCents), $method);

        if ($close) {
            app(CloseTableAction::class)->execute($session->refresh(), $actor);
        }

        return $session->refresh();
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    protected function dayClosePreview(Restaurant $restaurant, User $user, array $query = []): array
    {
        $uri = "/api/v1/restaurants/{$restaurant->id}/day-close/preview".($query !== [] ? '?'.http_build_query($query) : '');

        return $this->as($user)->getJson($uri)->assertOk()->json('data');
    }

    /**
     * Preview, then POST the close with the preview's period and expected
     * cash; counted_cash defaults to the expected cash (no difference).
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function closeDay(Restaurant $restaurant, User $user, array $overrides = [], ?string $idempotencyKey = null): TestResponse
    {
        $preview = $this->dayClosePreview($restaurant, $user, isset($overrides['opening_float']) ? ['opening_float' => $overrides['opening_float']] : []);

        $payload = array_merge([
            'period_started_at' => $preview['period']['period_started_at'],
            'expected_cash_seen' => $preview['cash']['expected_cash'],
            'counted_cash' => $preview['cash']['expected_cash'],
        ], $overrides);

        return $this->as($user)->postJson(
            "/api/v1/restaurants/{$restaurant->id}/day-closes",
            $payload,
            ['Idempotency-Key' => $idempotencyKey ?? (string) Str::uuid()],
        );
    }

    protected function setDefaultOpeningFloat(Restaurant $restaurant, ?string $amount): void
    {
        $restaurant->settings()->update(['default_opening_float' => $amount]);
    }

    /**
     * @param  array<string, int>  $ratings  overall/food/service/wait_time
     */
    protected function createFeedback(TableSession $session, array $ratings, ?string $comment = null): CustomerFeedback
    {
        return CustomerFeedback::query()->create([
            'organization_id' => $session->restaurant->organization_id,
            'restaurant_id' => $session->restaurant_id,
            'table_session_id' => $session->id,
            'waiter_id' => $session->assigned_waiter_user_id,
            'first_name' => 'Ana',
            'last_name' => 'Private-Surname',
            'overall_rating' => $ratings['overall'],
            'food_rating' => $ratings['food'] ?? $ratings['overall'],
            'service_rating' => $ratings['service'] ?? $ratings['overall'],
            'wait_time_rating' => $ratings['wait_time'] ?? $ratings['overall'],
            'experience_comment' => $comment,
            'improvement_comment' => null,
            'contact' => 'ana.private@example.com',
            'submitted_at' => now(),
        ]);
    }
}
