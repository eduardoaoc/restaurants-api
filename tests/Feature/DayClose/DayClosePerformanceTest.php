<?php

namespace Tests\Feature\DayClose;

use App\Actions\Catalog\UpdateRestaurantProductAction;
use App\Actions\DayClose\BuildDayClosePreviewAction;
use App\Actions\DayClose\CloseRestaurantDayAction;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithDayClose;
use Tests\Concerns\InteractsWithOrders;
use Tests\Concerns\InteractsWithPayments;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * CARTA 9.1A — preview/close query count is constant: it never grows with
 * the number of orders, products, reviews, availability changes or
 * sessions in the period (no N+1).
 */
class DayClosePerformanceTest extends TestCase
{
    use InteractsWithDayClose, InteractsWithOrders, InteractsWithPayments, InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRolesAndPermissions();
    }

    /**
     * @return array{0: Restaurant, 1: User}
     */
    private function restaurantWithVolume(int $volume): array
    {
        [, $owner, $restaurant] = $this->createTenant();
        $this->setDefaultOpeningFloat($restaurant, '0.00');
        $this->at('2026-10-02 18:00:00');

        for ($i = 0; $i < $volume; $i++) {
            $session = $this->servedPaidSession($restaurant, $owner, method: ['cash', 'card', 'other'][$i % 3]);
            $this->createFeedback($session, ['overall' => $i % 5 + 1, 'food' => 2]);
            $product = $this->createRestaurantProduct($restaurant, $this->createProduct($restaurant->organization));
            app(UpdateRestaurantProductAction::class)->execute($product, $owner, ['available' => false]);
        }

        $this->at('2026-10-02 21:00:00');

        return [$restaurant->refresh(), $owner];
    }

    private function countQueries(callable $callback): int
    {
        $count = 0;
        DB::listen(function () use (&$count) {
            $count++;
        });
        $callback();

        return $count;
    }

    public function test_preview_and_close_query_counts_do_not_grow_with_volume(): void
    {
        [$small, $ownerSmall] = $this->restaurantWithVolume(5);
        [$large, $ownerLarge] = $this->restaurantWithVolume(15);

        $previewSmall = $this->countQueries(fn () => app(BuildDayClosePreviewAction::class)->execute($small));
        $previewLarge = $this->countQueries(fn () => app(BuildDayClosePreviewAction::class)->execute($large));
        $this->assertSame($previewSmall, $previewLarge);

        $payload = fn (Restaurant $restaurant, string $key) => [
            'idempotency_key' => $key,
            'period_started_at' => app(BuildDayClosePreviewAction::class)->execute($restaurant)['period']['period_started_at'],
            'expected_cash_seen' => app(BuildDayClosePreviewAction::class)->execute($restaurant)['cash']['expected_cash'],
            'counted_cash' => app(BuildDayClosePreviewAction::class)->execute($restaurant)['cash']['expected_cash'],
        ];
        $smallPayload = $payload($small, 'small');
        $largePayload = $payload($large, 'large');

        $closeSmall = $this->countQueries(fn () => app(CloseRestaurantDayAction::class)->execute($small, $ownerSmall, $smallPayload));
        $closeLarge = $this->countQueries(fn () => app(CloseRestaurantDayAction::class)->execute($large, $ownerLarge, $largePayload));
        $this->assertSame($closeSmall, $closeLarge);
    }
}
