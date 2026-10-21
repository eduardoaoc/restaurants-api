<?php

namespace Tests\Concerns;

use App\Actions\DailyOperation\OpenDailyOperationAction;
use App\Models\Restaurant;
use App\Models\RestaurantDailyOperation;
use App\Models\RestaurantDayClose;
use App\Models\User;
use App\Support\DayClose\DayClosePeriodResolver;
use App\Support\DayClose\DayCloseReport;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use LogicException;

/**
 * Operational-day fixture. Since DailyOperationGuard refuses new sessions,
 * orders and public requests without an open day, createTable() and
 * openSession() open one for tests that are about something else — at the
 * current (possibly travelled) time, with the float the existing precedence
 * suggests (0.00 when none), so cash expectations are unchanged.
 *
 * Tests about the day itself call withoutDailyOperationFixture() and open
 * the day explicitly through the real endpoint/action (openDay()).
 */
trait InteractsWithDailyOperations
{
    private bool $dailyOperationFixture = true;

    protected function withoutDailyOperationFixture(): void
    {
        $this->dailyOperationFixture = false;
    }

    protected function ensureDailyOperationFixture(Restaurant $restaurant): void
    {
        if (! $this->dailyOperationFixture || $restaurant->openDailyOperation()->exists()) {
            return;
        }

        $restaurant->load('settings'); // the factory may have cached a null relation
        $settings = $restaurant->settings;
        $now = CarbonImmutable::now('UTC')->startOfSecond();
        $businessDate = DayClosePeriodResolver::businessDateOf($now, $settings->timezone, $settings->business_day_cutoff_time);
        $lastClosed = RestaurantDayClose::query()->where('restaurant_id', $restaurant->id)->max('business_date');

        if ($lastClosed !== null && $businessDate <= substr((string) $lastClosed, 0, 10)) {
            throw new LogicException("Fixture: business date {$businessDate} is already closed — travel past the cutoff before opening a new day.");
        }

        $suggested = app(DayCloseReport::class)->suggestedOpeningFloat($restaurant, app(DayClosePeriodResolver::class)->resolve($restaurant, $now));

        RestaurantDailyOperation::query()->create([
            'public_id' => (string) Str::uuid(),
            'organization_id' => $restaurant->organization_id,
            'restaurant_id' => $restaurant->id,
            'business_date' => $businessDate,
            'timezone' => $settings->timezone,
            'currency' => $settings->currency,
            'status' => RestaurantDailyOperation::STATUS_OPEN,
            'opened_by_name_snapshot' => 'Test fixture',
            'opened_at' => $now,
            'opening_float' => Money::centsToDecimal($suggested['cents'] ?? 0),
            'suggested_opening_float' => $suggested['cents'] !== null ? Money::centsToDecimal($suggested['cents']) : null,
            'suggested_opening_float_source' => $suggested['source'],
            'idempotency_key' => 'fixture-'.Str::uuid(),
            'payload_hash' => str_repeat('0', 64),
        ]);
    }

    /**
     * Opens the day through the real action (lock, rules, audit, event).
     */
    protected function openDay(Restaurant $restaurant, User $user, string $openingFloat = '0.00', ?string $key = null): RestaurantDailyOperation
    {
        $restaurant->load('settings');

        return app(OpenDailyOperationAction::class)->execute($restaurant, $user, [
            'opening_float' => $openingFloat,
            'idempotency_key' => $key ?? (string) Str::uuid(),
        ])['daily_operation'];
    }
}
