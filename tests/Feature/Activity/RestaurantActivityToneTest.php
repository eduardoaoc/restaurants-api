<?php

namespace Tests\Feature\Activity;

use App\Models\RestaurantActivityEvent;
use App\Support\Activity\ActivityActor;
use App\Support\Activity\RestaurantActivityRecorder;
use App\Support\Activity\RestaurantActivityType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * CARTA 6.1A (ajuste final) — every activity type has an explicit,
 * derived tone (neutral/positive/warning/critical); REST exposes it; it is
 * never stored. Tone is presentation semantics only, not "requires
 * action".
 */
class RestaurantActivityToneTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    /**
     * The full, frozen classification — one row per catalog type.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function toneByType(): array
    {
        return [
            'table_session.opened' => ['table_session.opened', 'neutral'],
            'table_session.voided' => ['table_session.voided', 'neutral'],
            'order.created' => ['order.created', 'neutral'],
            'order.approved' => ['order.approved', 'neutral'],
            'order.accepted' => ['order.accepted', 'neutral'],
            'order.preparing' => ['order.preparing', 'neutral'],
            'waiter_request.acknowledged' => ['waiter_request.acknowledged', 'neutral'],
            'bill_request.acknowledged' => ['bill_request.acknowledged', 'neutral'],
            'order.ready' => ['order.ready', 'positive'],
            'order.served' => ['order.served', 'positive'],
            'payment.recorded' => ['payment.recorded', 'positive'],
            'table_session.closed' => ['table_session.closed', 'positive'],
            'day_close.completed' => ['day_close.completed', 'positive'],
            'waiter_request.completed' => ['waiter_request.completed', 'positive'],
            'bill_request.completed' => ['bill_request.completed', 'positive'],
            'product.marked_available' => ['product.marked_available', 'positive'],
            'waiter_request.created' => ['waiter_request.created', 'warning'],
            'bill_request.created' => ['bill_request.created', 'warning'],
            'product.marked_unavailable' => ['product.marked_unavailable', 'warning'],
            'order.rejected' => ['order.rejected', 'critical'],
        ];
    }

    #[DataProvider('toneByType')]
    public function test_each_type_has_its_expected_tone(string $type, string $expectedTone): void
    {
        $this->assertSame($expectedTone, RestaurantActivityType::tone($type));
    }

    public function test_the_dataset_covers_every_catalog_type_and_nothing_else(): void
    {
        $this->assertEqualsCanonicalizing(RestaurantActivityType::all(), array_keys(self::toneByType()));
        $this->assertEqualsCanonicalizing(RestaurantActivityType::all(), array_keys(RestaurantActivityType::TONE_BY_TYPE));
    }

    public function test_every_tone_is_a_known_value_and_critical_stays_rare(): void
    {
        foreach (RestaurantActivityType::TONE_BY_TYPE as $tone) {
            $this->assertContains($tone, RestaurantActivityType::TONES);
            $this->assertDoesNotMatchRegularExpression('/^#|green|red|yellow|orange/i', $tone);
        }

        $this->assertSame(
            [RestaurantActivityType::ORDER_REJECTED],
            array_keys(array_filter(RestaurantActivityType::TONE_BY_TYPE, fn (string $tone) => $tone === RestaurantActivityType::TONE_CRITICAL)),
        );
    }

    public function test_an_unknown_type_has_no_silent_default(): void
    {
        $this->expectException(InvalidArgumentException::class);

        RestaurantActivityType::tone('order.teleported');
    }

    public function test_rest_item_exposes_the_derived_tone_and_nothing_is_persisted(): void
    {
        $this->seedRolesAndPermissions();
        [, $owner, $restaurant] = $this->createTenant();
        $recorder = app(RestaurantActivityRecorder::class);

        $recorder->record($restaurant->id, RestaurantActivityType::TABLE_SESSION_OPENED, ActivityActor::staff($owner), metadata: ['guest_count' => 2]);
        $recorder->record($restaurant->id, RestaurantActivityType::ORDER_REJECTED, ActivityActor::staff($owner));
        $recorder->record($restaurant->id, RestaurantActivityType::TABLE_SESSION_CLOSED, ActivityActor::staff($owner), metadata: ['total' => '10.00']);

        $items = $this->actingAs($owner, 'web')
            ->getJson("/api/v1/restaurants/{$restaurant->id}/activity")
            ->assertOk()
            ->json('data.activity');

        $this->assertSame(['positive', 'critical', 'neutral'], array_column($items, 'tone'));
        $this->assertArrayNotHasKey('tone', RestaurantActivityEvent::query()->firstOrFail()->getAttributes());
    }
}
