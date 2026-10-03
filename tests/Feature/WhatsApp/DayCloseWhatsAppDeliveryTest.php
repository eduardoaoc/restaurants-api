<?php

namespace Tests\Feature\WhatsApp;

use App\Jobs\SendDayCloseWhatsApp;
use App\Models\Restaurant;
use App\Models\RestaurantDayClose;
use App\Models\RestaurantDayCloseDelivery;
use App\Models\RestaurantReportRecipient;
use App\Models\User;
use App\Support\WhatsApp\DayCloseWhatsAppPresenter;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\InteractsWithDayClose;
use Tests\Concerns\InteractsWithOrders;
use Tests\Concerns\InteractsWithPayments;
use Tests\Concerns\InteractsWithTenants;
use Tests\Concerns\InteractsWithWhatsApp;
use Tests\TestCase;

/**
 * CARTA 9.1E — automatic delivery on close + the send job.
 */
class DayCloseWhatsAppDeliveryTest extends TestCase
{
    use InteractsWithDayClose, InteractsWithOrders, InteractsWithPayments, InteractsWithTenants, InteractsWithWhatsApp, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRolesAndPermissions();
        $this->withHeader('Origin', 'http://localhost:5173');
        $this->configureWhatsApp();
    }

    /**
     * @return array{0: Restaurant, 1: User}
     */
    private function restaurantWithSales(bool $enableWhatsApp = true): array
    {
        [, $owner, $restaurant] = $this->createTenant();
        $restaurant->update(['name' => 'AFORO Ruzafa']);
        $this->setDefaultOpeningFloat($restaurant, '100.00');
        if ($enableWhatsApp) {
            $this->enableWhatsApp($restaurant, $owner);
        }
        $this->at('2026-10-02 18:00:00');
        $this->servedPaidSession($restaurant, $owner, '1842.50', 'card', productName: 'Paella');
        $this->servedPaidSession($restaurant, $owner, '25.00', 'cash', productName: 'Agua');
        $this->at('2026-10-02 21:00:00');

        return [$restaurant->refresh(), $owner];
    }

    /**
     * Run the job's handle() in-process (Queue::fake also intercepts
     * dispatch_sync of ShouldQueue jobs).
     */
    private function runJob(int $deliveryId): void
    {
        app()->call([new SendDayCloseWhatsApp($deliveryId), 'handle']);
    }

    public function test_disabled_restaurant_closes_without_any_delivery(): void
    {
        Http::fake();
        [$restaurant, $owner] = $this->restaurantWithSales(enableWhatsApp: false);

        $this->closeDay($restaurant, $owner)->assertCreated()->assertJsonPath('data.whatsapp', null);

        $this->assertSame(0, RestaurantDayCloseDelivery::query()->count());
        Http::assertNothingSent();
    }

    public function test_enabled_close_creates_one_pending_delivery_and_dispatches_after_commit(): void
    {
        Queue::fake();
        [$restaurant, $owner] = $this->restaurantWithSales();

        $this->closeDay($restaurant, $owner)->assertCreated()
            ->assertJsonPath('data.whatsapp.status', 'pending')
            ->assertJsonPath('data.whatsapp.kind', 'automatic');

        $delivery = RestaurantDayCloseDelivery::query()->sole();
        $this->assertSame('pending', $delivery->status);
        $this->assertSame('automatic', $delivery->kind);
        $this->assertSame('+34 ••• •• 56 78', $delivery->recipient_phone_masked);
        $this->assertSame('+34612345678', $delivery->recipient_phone_e164);
        $this->assertSame('aforo_cierre_diario', $delivery->template_name);
        Queue::assertPushed(SendDayCloseWhatsApp::class, fn (SendDayCloseWhatsApp $job) => $job->deliveryId === $delivery->id);
    }

    public function test_a_rolled_back_close_dispatches_nothing(): void
    {
        Queue::fake();
        [$restaurant, $owner] = $this->restaurantWithSales();
        $this->openSession($this->createTable($restaurant), $owner); // blocker -> 422, rolled back

        $this->closeDay($restaurant, $owner)->assertUnprocessable();

        $this->assertSame(0, RestaurantDayCloseDelivery::query()->count());
        Queue::assertNotPushed(SendDayCloseWhatsApp::class);
    }

    public function test_provider_success_marks_accepted_with_the_exact_template_payload(): void
    {
        Http::fake([self::WA_MESSAGES_URL => Http::response($this->metaAccepted('wamid.ABC123'), 200)]);
        [$restaurant, $owner] = $this->restaurantWithSales();

        $closeId = $this->closeDay($restaurant, $owner, ['counted_cash' => '123.00'])->assertCreated()->json('data.id');

        $delivery = RestaurantDayCloseDelivery::query()->sole();
        $this->assertSame('accepted', $delivery->status); // accepted, NOT sent
        $this->assertSame('wamid.ABC123', $delivery->provider_message_id);
        $this->assertNotNull($delivery->accepted_at);
        $this->assertNull($delivery->sent_at);
        $this->assertSame(1, $delivery->attempts);

        Http::assertSentCount(1);
        $request = Http::recorded()[0][0];
        $body = $request->data();
        $params = collect($body['template']['components'][0]['parameters'])->mapWithKeys(fn ($p) => [$p['parameter_name'] => $p['text']])->all();

        $this->assertSame(self::WA_MESSAGES_URL, $request->url());
        $this->assertSame('POST', $request->method());
        $this->assertTrue($request->hasHeader('Authorization'));
        $this->assertStringStartsWith('Bearer ', $request->header('Authorization')[0]);
        $this->assertSame('whatsapp', $body['messaging_product']);
        $this->assertSame('individual', $body['recipient_type']);
        $this->assertSame('+34612345678', $body['to']);
        $this->assertSame('template', $body['type']);
        $this->assertSame('aforo_cierre_diario', $body['template']['name']);
        $this->assertSame(['code' => 'es_ES'], $body['template']['language']);
        $this->assertSame(DayCloseWhatsAppPresenter::PARAMETERS, array_keys($params));
        $this->assertSame([
            'restaurant' => 'AFORO Ruzafa',
            'business_date' => '02/10/2026',
            'total' => '1.867,50 €',
            'cash' => '25,00 €',
            'card' => '1.842,50 €',
            'other' => '0,00 €',
            'cash_difference' => '−2,00 €',
            'orders' => '2',
            'guests' => '4',
            'average_ticket' => '933,75 €',
            'top_product' => 'Cola (1)',
            'critical_reviews' => '0',
            'delays' => '0',
            'unavailable_products' => '0',
            'closed_by' => $owner->name,
            'report_url' => "https://app.aforo.test/app/day-close/{$closeId}",
        ], $params);
    }

    public function test_provider_failure_never_touches_the_close(): void
    {
        Http::fake([self::WA_MESSAGES_URL => Http::response(['error' => ['code' => 132001, 'title' => 'Template name does not exist in the translation', 'error_data' => ['details' => 'template aforo_cierre_diario does not exist in es_ES']]], 404)]);
        [$restaurant, $owner] = $this->restaurantWithSales();

        $response = $this->closeDay($restaurant, $owner)->assertCreated();
        $dayClose = RestaurantDayClose::query()->sole();

        $delivery = RestaurantDayCloseDelivery::query()->sole();
        $this->assertSame('failed', $delivery->status);
        $this->assertSame('132001', $delivery->failure_code);
        $this->assertSame('template aforo_cierre_diario does not exist in es_ES', $delivery->failure_reason);
        $this->assertNotNull($delivery->failed_at);
        $this->assertSame($response->json('data.report_sha256'), $dayClose->report_sha256);
        Http::assertSentCount(1);
    }

    public function test_idempotent_close_replay_creates_no_second_delivery(): void
    {
        Http::fake([self::WA_MESSAGES_URL => Http::response($this->metaAccepted(), 200)]);
        [$restaurant, $owner] = $this->restaurantWithSales();
        $preview = $this->dayClosePreview($restaurant, $owner);
        $payload = ['period_started_at' => $preview['period']['period_started_at'], 'expected_cash_seen' => $preview['cash']['expected_cash'], 'counted_cash' => $preview['cash']['expected_cash']];
        $uri = "/api/v1/restaurants/{$restaurant->id}/day-closes";

        $this->as($owner)->postJson($uri, $payload, ['Idempotency-Key' => 'close-key'])->assertCreated();
        $this->as($owner)->postJson($uri, $payload, ['Idempotency-Key' => 'close-key'])->assertOk(); // double click / retry

        $this->assertSame(1, RestaurantDayCloseDelivery::query()->count());
        Http::assertSentCount(1);
    }

    public function test_one_automatic_delivery_per_close_is_enforced_by_the_database(): void
    {
        Queue::fake();
        [$restaurant, $owner] = $this->restaurantWithSales();
        $this->closeDay($restaurant, $owner)->assertCreated();
        $existing = RestaurantDayCloseDelivery::query()->sole();

        $this->expectException(UniqueConstraintViolationException::class);
        RestaurantDayCloseDelivery::query()->create([...$existing->only(['restaurant_day_close_id', 'restaurant_id', 'channel', 'kind']), 'status' => 'pending']);
    }

    public function test_enabled_but_incomplete_configuration_is_skipped_and_the_close_succeeds(): void
    {
        Http::fake();
        [$restaurant, $owner] = $this->restaurantWithSales();
        config(['whatsapp.access_token' => null]);

        $this->closeDay($restaurant, $owner)->assertCreated()
            ->assertJsonPath('data.whatsapp.status', 'skipped')
            ->assertJsonPath('data.whatsapp.failure_code', 'configuration_incomplete');
        Http::assertNothingSent();
    }

    public function test_enabled_without_usable_recipient_is_skipped(): void
    {
        Http::fake();
        [$restaurant, $owner] = $this->restaurantWithSales();
        // Consent revoked behind the switch's back (e.g. data repair).
        RestaurantReportRecipient::query()->update(['consent_revoked_at' => now()]);

        $this->closeDay($restaurant, $owner)->assertCreated()->assertJsonPath('data.whatsapp.failure_code', 'consent_missing');

        RestaurantReportRecipient::query()->update(['active' => false]);
        $this->at('2026-10-03 21:00:00');
        $this->closeDay($restaurant, $owner)->assertCreated()->assertJsonPath('data.whatsapp.failure_code', 'recipient_missing');
        Http::assertNothingSent();
    }

    public function test_job_is_at_most_once(): void
    {
        Queue::fake();
        Http::fake([self::WA_MESSAGES_URL => Http::response($this->metaAccepted('wamid.ONCE'), 200)]);
        [$restaurant, $owner] = $this->restaurantWithSales();
        $this->closeDay($restaurant, $owner)->assertCreated();
        $delivery = RestaurantDayCloseDelivery::query()->sole();

        $this->runJob($delivery->id);
        $this->runJob($delivery->id); // retry / duplicate job

        $this->assertSame('accepted', $delivery->refresh()->status);
        Http::assertSentCount(1);

        // A provider id already present is never re-sent, whatever the status.
        $delivery->update(['status' => 'pending']);
        $this->runJob($delivery->id);
        Http::assertSentCount(1);
    }

    public function test_an_interrupted_previous_attempt_is_never_resent(): void
    {
        Queue::fake();
        Http::fake();
        [$restaurant, $owner] = $this->restaurantWithSales();
        $this->closeDay($restaurant, $owner)->assertCreated();
        $delivery = RestaurantDayCloseDelivery::query()->sole();
        $delivery->update(['sending_started_at' => now()->subMinutes(5), 'attempts' => 1]); // worker died mid-call (stale claim)

        $this->runJob($delivery->id);

        $delivery->refresh();
        $this->assertSame('failed', $delivery->status);
        $this->assertSame('unknown_outcome', $delivery->failure_code);
        Http::assertNothingSent();
    }

    public function test_timeout_is_an_unknown_outcome_and_not_retried(): void
    {
        Http::fake([self::WA_MESSAGES_URL => fn () => throw new ConnectionException('cURL error 28: Operation timed out after 10001 milliseconds')]);
        [$restaurant, $owner] = $this->restaurantWithSales();

        $this->closeDay($restaurant, $owner)->assertCreated();

        $delivery = RestaurantDayCloseDelivery::query()->sole();
        $this->assertSame('failed', $delivery->status);
        $this->assertSame('unknown_outcome:timeout', $delivery->failure_code);
        $this->assertSame(1, $delivery->attempts);
    }

    public function test_server_error_without_body_is_unknown_and_not_retried(): void
    {
        Http::fake([self::WA_MESSAGES_URL => Http::response('<html>Bad gateway</html>', 502)]);
        [$restaurant, $owner] = $this->restaurantWithSales();

        $this->closeDay($restaurant, $owner)->assertCreated();

        $this->assertSame('unknown_outcome:http_502', RestaurantDayCloseDelivery::query()->sole()->failure_code);
        Http::assertSentCount(1);
    }

    public function test_documented_transient_error_releases_for_retry_until_max_attempts(): void
    {
        Queue::fake();
        Http::fake([self::WA_MESSAGES_URL => Http::response(['error' => ['code' => 130429, 'title' => 'Rate limit hit']], 429)]);
        [$restaurant, $owner] = $this->restaurantWithSales();
        $this->closeDay($restaurant, $owner)->assertCreated();
        $delivery = RestaurantDayCloseDelivery::query()->sole();

        foreach ([1, 2] as $attempt) {
            $this->runJob($delivery->id);
            $delivery->refresh();
            $this->assertSame('pending', $delivery->status, "attempt {$attempt}");
            $this->assertSame($attempt, $delivery->attempts);
            $this->assertNull($delivery->sending_started_at);
            $this->assertSame('130429', $delivery->failure_code);
        }

        $this->runJob($delivery->id);
        $this->assertSame('failed', $delivery->refresh()->status);
        $this->assertSame(3, $delivery->attempts);
        Http::assertSentCount(3);
    }

    public function test_no_token_or_phone_ever_reaches_logs_or_api_responses(): void
    {
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged) {
            $logged[] = $event->message.' '.json_encode($event->context);
        });
        Http::fake([self::WA_MESSAGES_URL => Http::response(['error' => ['code' => 131026, 'title' => 'Message undeliverable', 'error_data' => ['details' => 'Recipient +34612345678 is not on WhatsApp']]], 400)]);
        [$restaurant, $owner] = $this->restaurantWithSales();

        $close = $this->closeDay($restaurant, $owner)->assertCreated();
        $deliveries = $this->as($owner)->getJson("/api/v1/day-closes/{$close->json('data.id')}/deliveries")->assertOk();

        $delivery = RestaurantDayCloseDelivery::query()->sole();
        $this->assertSame('Recipient [redacted] is not on WhatsApp', $delivery->failure_reason);

        $everything = implode("\n", $logged).$close->getContent().$deliveries->getContent().json_encode(DB::table('restaurant_day_close_deliveries')->get(['failure_reason', 'failure_code']));
        foreach ([self::WA_TOKEN, self::WA_APP_SECRET, self::WA_VERIFY_TOKEN, '612345678'] as $secret) {
            $this->assertStringNotContainsString($secret, $everything);
        }
    }

    public function test_message_uses_the_close_and_recipient_snapshots_not_current_data(): void
    {
        Queue::fake();
        Http::fake([self::WA_MESSAGES_URL => Http::response($this->metaAccepted(), 200)]);
        [$restaurant, $owner] = $this->restaurantWithSales();
        $this->closeDay($restaurant, $owner)->assertCreated();
        $delivery = RestaurantDayCloseDelivery::query()->sole();

        // After the close: restaurant/products/settings change, payments
        // arrive and the recipient's number changes.
        $restaurant->update(['name' => 'Renamed Restaurant']);
        DB::table('products')->update(['internal_name' => 'Renamed']);
        $restaurant->settings()->update(['timezone' => 'Asia/Tokyo']);
        $this->at('2026-10-03 10:00:00');
        $this->servedPaidSession($restaurant, $owner, '999.00', 'cash');
        $this->putWhatsAppSettings($restaurant, $owner, true, ['name' => 'Nuevo', 'phone' => '+34699000111', 'consent_confirmed' => true])->assertOk();

        $this->runJob($delivery->id);

        Http::assertSent(function (HttpRequest $request) {
            $params = collect($request->data()['template']['components'][0]['parameters'])->mapWithKeys(fn ($p) => [$p['parameter_name'] => $p['text']]);

            return $request->data()['to'] === '+34612345678'
                && $params['restaurant'] === 'AFORO Ruzafa'
                && $params['total'] === '1.867,50 €'
                && $params['business_date'] === '02/10/2026';
        });
    }

    public function test_top_product_placeholder_and_single_line_parameters(): void
    {
        Http::fake([self::WA_MESSAGES_URL => Http::response($this->metaAccepted(), 200)]);
        [, $owner, $restaurant] = $this->createTenant();
        $restaurant->update(['name' => "Bar\tCentral\n  Valencia"]);
        $this->setDefaultOpeningFloat($restaurant, '0.00');
        $this->enableWhatsApp($restaurant, $owner);
        $this->at('2026-10-02 21:00:00');

        $this->closeDay($restaurant, $owner)->assertCreated();

        Http::assertSent(function (HttpRequest $request) {
            $params = collect($request->data()['template']['components'][0]['parameters'])->mapWithKeys(fn ($p) => [$p['parameter_name'] => $p['text']]);

            return $params['top_product'] === 'Sin ventas registradas'
                && $params['restaurant'] === 'Bar Central Valencia'
                && $params->every(fn ($text) => $text !== '' && ! str_contains($text, "\n") && ! str_contains($text, "\t") && $text !== 'null');
        });
    }
}
