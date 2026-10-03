<?php

namespace Tests\Feature\WhatsApp;

use App\Jobs\SendDayCloseWhatsApp;
use App\Models\RestaurantDayCloseDelivery;
use App\Models\RestaurantReportRecipient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithDayClose;
use Tests\Concerns\InteractsWithOrders;
use Tests\Concerns\InteractsWithPayments;
use Tests\Concerns\InteractsWithTenants;
use Tests\Concerns\InteractsWithWhatsApp;
use Tests\TestCase;

/**
 * CARTA 9.1E final validation — the send job's retry state machine.
 * Each execution is run in-process (handle()) to control attempts exactly.
 */
class SendDayCloseWhatsAppRetryTest extends TestCase
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
     * A closed day with its automatic delivery still pending (job not run).
     */
    private function pendingDelivery(): RestaurantDayCloseDelivery
    {
        Queue::fake();
        [, $owner, $restaurant] = $this->createTenant();
        $this->setDefaultOpeningFloat($restaurant, '0.00');
        $this->enableWhatsApp($restaurant, $owner);
        $this->at('2026-10-02 21:00:00');
        $this->closeDay($restaurant, $owner)->assertCreated();

        $delivery = RestaurantDayCloseDelivery::query()->latest('id')->firstOrFail();
        $this->assertSame('pending', $delivery->status);

        return $delivery;
    }

    private function runJob(int $deliveryId): void
    {
        app()->call([new SendDayCloseWhatsApp($deliveryId), 'handle']);
    }

    private function assertRetryableState(RestaurantDayCloseDelivery $delivery, int $attempts, string $code): void
    {
        $delivery->refresh();
        $this->assertSame('pending', $delivery->status);
        $this->assertNull($delivery->sending_started_at, 'a safe-to-retry attempt must release its claim');
        $this->assertSame($attempts, $delivery->attempts);
        $this->assertSame($code, $delivery->failure_code);
        $this->assertNull($delivery->provider_message_id);
    }

    private function assertAcceptedAfter(RestaurantDayCloseDelivery $delivery, int $attempts, string $wamid): void
    {
        $delivery->refresh();
        $this->assertSame('accepted', $delivery->status);
        $this->assertSame($wamid, $delivery->provider_message_id);
        $this->assertSame($attempts, $delivery->attempts);
        $this->assertNull($delivery->sending_started_at);
        $this->assertNull($delivery->failure_code, 'success clears the earlier transient failure');
        $this->assertNull($delivery->failure_reason);
        $this->assertNull($delivery->failed_at);
    }

    public function test_meta_429_then_success(): void
    {
        $delivery = $this->pendingDelivery();
        Http::fake([self::WA_MESSAGES_URL => Http::sequence()
            ->push(['error' => ['code' => 130429, 'title' => 'Rate limit hit']], 429)
            ->push($this->metaAccepted('wamid.AFTER429'), 200)]);

        $this->runJob($delivery->id);
        $this->assertRetryableState($delivery, 1, '130429');

        $this->runJob($delivery->id);
        $this->assertAcceptedAfter($delivery, 2, 'wamid.AFTER429');
        Http::assertSentCount(2);
    }

    public function test_curl_7_connection_refused_then_success(): void
    {
        $delivery = $this->pendingDelivery();
        $calls = 0;
        Http::fake([self::WA_MESSAGES_URL => function () use (&$calls) {
            if (++$calls === 1) {
                throw new ConnectionException('cURL error 7: Failed to connect to graph.facebook.com port 443: Connection refused');
            }

            return Http::response($this->metaAccepted('wamid.AFTER7'), 200);
        }]);

        $this->runJob($delivery->id);
        $this->assertRetryableState($delivery, 1, 'connection_failed');

        $this->runJob($delivery->id);
        $this->assertAcceptedAfter($delivery, 2, 'wamid.AFTER7');
        $this->assertSame(2, $calls);
    }

    public function test_curl_6_dns_failure_then_success(): void
    {
        $delivery = $this->pendingDelivery();
        $calls = 0;
        Http::fake([self::WA_MESSAGES_URL => function () use (&$calls) {
            if (++$calls === 1) {
                throw new ConnectionException('cURL error 6: Could not resolve host: graph.facebook.com');
            }

            return Http::response($this->metaAccepted('wamid.AFTER6'), 200);
        }]);

        $this->runJob($delivery->id);
        $this->assertRetryableState($delivery, 1, 'connection_failed');

        $this->runJob($delivery->id);
        $this->assertAcceptedAfter($delivery, 2, 'wamid.AFTER6');
        $this->assertSame(2, $calls);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function unknownOutcomes(): array
    {
        return ['timeout after a possible send' => ['timeout'], '5xx without an error body' => ['http_503']];
    }

    #[DataProvider('unknownOutcomes')]
    public function test_unknown_outcome_is_failed_and_an_accidental_rerun_never_calls_meta(string $code): void
    {
        $delivery = $this->pendingDelivery();
        $calls = 0;
        Http::fake([self::WA_MESSAGES_URL => function () use (&$calls, $code) {
            $calls++;

            return $code === 'timeout'
                ? throw new ConnectionException('cURL error 28: Operation timed out after 10001 milliseconds')
                : Http::response('<html>Service Unavailable</html>', 503);
        }]);

        $this->runJob($delivery->id);
        $delivery->refresh();
        $this->assertSame('failed', $delivery->status);
        $this->assertSame("unknown_outcome:{$code}", $delivery->failure_code);
        $this->assertNull($delivery->sending_started_at);

        $this->runJob($delivery->id); // accidental retry / duplicate job
        $this->assertSame('failed', $delivery->refresh()->status);
        $this->assertSame(1, $calls, 'Meta must be called exactly once');
    }

    public function test_crash_after_meta_accepted_is_never_resent(): void
    {
        // Meta accepted, then the process died before provider_message_id
        // was saved: the claim is left behind and becomes stale.
        $delivery = $this->pendingDelivery();
        $delivery->update(['sending_started_at' => now(), 'attempts' => 1]);
        Http::fake();

        $this->travel(SendDayCloseWhatsApp::CLAIM_STALE_AFTER_SECONDS + 1)->seconds();
        $this->runJob($delivery->id);

        $delivery->refresh();
        $this->assertSame('failed', $delivery->status);
        $this->assertSame('unknown_outcome', $delivery->failure_code);
        $this->assertSame(1, $delivery->attempts);
        Http::assertNothingSent();
    }

    public function test_a_fresh_claim_belongs_to_a_live_execution_and_is_left_alone(): void
    {
        $delivery = $this->pendingDelivery();
        $delivery->update(['sending_started_at' => now(), 'attempts' => 1]); // another run is mid-call
        Http::fake();

        $this->runJob($delivery->id);

        $delivery->refresh();
        $this->assertSame('pending', $delivery->status);
        $this->assertNotNull($delivery->sending_started_at);
        $this->assertSame(1, $delivery->attempts);
        Http::assertNothingSent();
    }

    public function test_existing_provider_message_id_is_never_sent_again(): void
    {
        $delivery = $this->pendingDelivery();
        $delivery->update(['provider_message_id' => 'wamid.ALREADY']); // even if still "pending"
        Http::fake();

        $this->runJob($delivery->id);
        $delivery->update(['status' => 'failed']);
        $this->runJob($delivery->id);

        Http::assertNothingSent();
        $this->assertSame('wamid.ALREADY', $delivery->refresh()->provider_message_id);
    }

    public function test_the_meta_call_happens_outside_any_transaction(): void
    {
        $delivery = $this->pendingDelivery();
        $baseline = DB::transactionLevel(); // the test's own RefreshDatabase wrapper
        $levelDuringHttp = null;
        Http::fake([self::WA_MESSAGES_URL => function () use (&$levelDuringHttp) {
            $levelDuringHttp = DB::transactionLevel();

            return Http::response($this->metaAccepted(), 200);
        }]);

        $this->runJob($delivery->id);

        $this->assertSame($baseline, $levelDuringHttp, 'the job must not hold a transaction (nor its row lock) during the HTTP call');
        $this->assertSame('accepted', $delivery->refresh()->status);
    }

    public function test_an_old_delivery_uses_its_recipient_snapshot_and_the_authenticated_report_url(): void
    {
        $delivery = $this->pendingDelivery();
        // The live recipient changes after the delivery was created.
        RestaurantReportRecipient::query()->update(['name' => 'Otra persona']);
        $this->putWhatsAppSettings($delivery->dayClose->restaurant, $delivery->dayClose->restaurant->organization->users()->first(), true, ['name' => 'Nuevo', 'phone' => '+34699000111', 'consent_confirmed' => true]);
        Http::fake([self::WA_MESSAGES_URL => Http::response($this->metaAccepted(), 200)]);
        config(['app.url' => 'https://api.aforo.test']);

        $this->runJob($delivery->id);

        $request = Http::recorded()[0][0];
        $params = collect($request->data()['template']['components'][0]['parameters'])->mapWithKeys(fn ($p) => [$p['parameter_name'] => $p['text']]);
        $this->assertSame('+34612345678', $request->data()['to']);
        $this->assertSame('Carlos Ruiz', $delivery->refresh()->recipient_name_snapshot);
        $this->assertSame("https://app.aforo.test/app/day-close/{$delivery->restaurant_day_close_id}", $params['report_url']);
        $this->assertStringNotContainsString('api.aforo.test', $params['report_url']);
        $this->assertStringNotContainsString('localhost', $params['report_url']);
    }
}
