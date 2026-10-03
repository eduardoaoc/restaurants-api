<?php

namespace App\Jobs;

use App\Models\RestaurantDayCloseDelivery;
use App\Support\WhatsApp\DayCloseWhatsAppPresenter;
use App\Support\WhatsApp\PhoneNumber;
use App\Support\WhatsApp\WhatsAppConfig;
use App\Support\WhatsApp\WhatsAppProvider;
use App\Support\WhatsApp\WhatsAppSendResult;
use App\Support\WhatsApp\WhatsAppTemplateMessage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends ONE Cierre Diario WhatsApp delivery (CARTA 9.1E). Carries only the
 * delivery id; the message is built from the persisted close snapshot and
 * the delivery's recipient snapshot — never recomputed, never the current
 * recipient.
 *
 * At-most-once by design (the Cloud API has no idempotency key):
 *   1. CLAIM under a row lock: only a `pending` delivery without
 *      provider_message_id and without an in-flight claim is sent; the
 *      claim (sending_started_at, attempts+1) is committed BEFORE the
 *      HTTP call, which happens outside any transaction.
 *   2. A pending delivery found WITH a claim:
 *      - claim younger than CLAIM_STALE_AFTER_SECONDS: another execution
 *        is still on its HTTP call (concurrent duplicate job) -> exit
 *        without touching anything;
 *      - older: that run died mid-call (e.g. after Meta accepted but
 *        before provider_message_id was saved) -> outcome unknown ->
 *        failed (unknown_outcome), never re-sent. A manual resend is the
 *        explicit way forward.
 *   3. Results: accepted -> `accepted` + provider_message_id (NOT
 *      "sent": that only comes from the webhook); rejected+retryable
 *      (Meta's documented transient codes; Meta answered with an error, so
 *      nothing was accepted) -> claim released (sending_started_at=null,
 *      status stays pending, attempts kept) and retried with backoff up to
 *      MAX_ATTEMPTS — the next run claims it again like a fresh one;
 *      anything else -> `failed` with a sanitized reason.
 *
 * Transaction boundaries: claim = short transaction (lock, validate,
 * reserve, COMMIT); the Meta HTTP call runs with NO transaction and NO row
 * lock held; record = a new short transaction.
 *
 * A failure here never touches the RestaurantDayClose.
 */
class SendDayCloseWhatsApp implements ShouldQueue
{
    use Queueable;

    public const MAX_ATTEMPTS = 3;

    /**
     * A claim older than this belongs to a dead run, never to a live one:
     * comfortably above the HTTP timeout (connect 5s + total 10s) plus the
     * claim/record transactions.
     */
    public const CLAIM_STALE_AFTER_SECONDS = 60;

    /** Seconds before the 2nd and 3rd attempt of a retryable rejection. */
    public const BACKOFF = [60, 300];

    /**
     * The queue's own retries are not used for provider outcomes (those are
     * classified and released explicitly); this only bounds releases.
     */
    public int $tries = self::MAX_ATTEMPTS;

    public function __construct(public readonly int $deliveryId) {}

    public function handle(WhatsAppProvider $provider, WhatsAppConfig $config): void
    {
        $delivery = $this->claim();

        if ($delivery === null) {
            return;
        }

        $message = new WhatsAppTemplateMessage(
            toE164: $delivery->recipient_phone_e164,
            templateName: $delivery->template_name ?: $config->templateName(),
            templateLanguage: $delivery->template_language ?: $config->templateLanguage(),
            bodyParameters: (new DayCloseWhatsAppPresenter($delivery->dayClose, $config))->parameters(),
        );

        $result = $config->readyToSend()
            ? $provider->sendTemplate($message)
            : WhatsAppSendResult::rejected('configuration_incomplete', 'WhatsApp is not configured on this AFORO installation.', false);

        $this->record($delivery->id, $result);
    }

    /**
     * Lock, validate and claim the delivery. Returns null when there is
     * nothing (left) to send.
     */
    private function claim(): ?RestaurantDayCloseDelivery
    {
        return DB::transaction(function () {
            $delivery = RestaurantDayCloseDelivery::query()->whereKey($this->deliveryId)->lockForUpdate()->first();

            if ($delivery === null || $delivery->status !== RestaurantDayCloseDelivery::STATUS_PENDING || $delivery->provider_message_id !== null) {
                return null;
            }

            if ($delivery->sending_started_at !== null) {
                if ($delivery->sending_started_at->greaterThan(now()->subSeconds(self::CLAIM_STALE_AFTER_SECONDS))) {
                    // Another execution is in flight right now: leave it alone.
                    return null;
                }

                $delivery->update([
                    'status' => RestaurantDayCloseDelivery::STATUS_FAILED,
                    'failure_code' => 'unknown_outcome',
                    'failure_reason' => 'A previous send attempt was interrupted; it may or may not have been delivered. Use a manual resend if needed.',
                    'failed_at' => now(),
                ]);
                Log::warning('whatsapp.delivery.unknown_outcome', ['delivery_id' => $delivery->id]);

                return null;
            }

            $delivery->update(['sending_started_at' => now(), 'attempts' => $delivery->attempts + 1]);

            return $delivery->load('dayClose');
        });
    }

    /**
     * The queue gave up on the job (e.g. worker crash loop / max tries):
     * a still-pending delivery is closed as failed so it never sits
     * pending forever — and never re-sent automatically.
     */
    public function failed(?Throwable $exception): void
    {
        RestaurantDayCloseDelivery::query()
            ->whereKey($this->deliveryId)
            ->where('status', RestaurantDayCloseDelivery::STATUS_PENDING)
            ->update([
                'status' => RestaurantDayCloseDelivery::STATUS_FAILED,
                'failure_code' => 'job_failed',
                'failure_reason' => 'The delivery job failed; it may or may not have been sent. Use a manual resend if needed.',
                'failed_at' => now(),
                'sending_started_at' => null,
            ]);
    }

    private function record(int $deliveryId, WhatsAppSendResult $result): void
    {
        DB::transaction(function () use ($deliveryId, $result) {
            $delivery = RestaurantDayCloseDelivery::query()->whereKey($deliveryId)->lockForUpdate()->firstOrFail();

            if ($result->outcome === WhatsAppSendResult::ACCEPTED) {
                // Meta has the message: its id always wins, and a failure
                // left by an earlier retryable attempt is cleared.
                $delivery->update([
                    'status' => RestaurantDayCloseDelivery::STATUS_ACCEPTED,
                    'provider_message_id' => $result->messageId,
                    'accepted_at' => now(),
                    'sending_started_at' => null,
                    'failure_code' => null,
                    'failure_reason' => null,
                    'failed_at' => null,
                ]);

                return;
            }

            if ($delivery->status !== RestaurantDayCloseDelivery::STATUS_PENDING) {
                return;
            }

            $reason = mb_substr(PhoneNumber::redact((string) $result->errorReason), 0, 255);

            if ($result->outcome === WhatsAppSendResult::REJECTED && $result->retryable && $delivery->attempts < self::MAX_ATTEMPTS) {
                // Meta answered with a transient error: nothing was accepted,
                // so releasing the claim and retrying cannot duplicate.
                $delivery->update(['sending_started_at' => null, 'failure_code' => $result->errorCode, 'failure_reason' => $reason]);
                $this->release(self::BACKOFF[min($delivery->attempts - 1, count(self::BACKOFF) - 1)]);

                return;
            }

            $delivery->update([
                'status' => RestaurantDayCloseDelivery::STATUS_FAILED,
                'failure_code' => $result->outcome === WhatsAppSendResult::UNKNOWN ? 'unknown_outcome:'.$result->errorCode : $result->errorCode,
                'failure_reason' => $reason,
                'failed_at' => now(),
                'sending_started_at' => null,
            ]);
            Log::warning('whatsapp.delivery.failed', ['delivery_id' => $delivery->id, 'code' => $result->errorCode, 'outcome' => $result->outcome]);
        });
    }
}
