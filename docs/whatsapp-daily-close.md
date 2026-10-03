# Cierre Diario por WhatsApp (CARTA 9.1E)

When a restaurant completes its Cierre Diario, AFORO sends a short summary
to the restaurant's configured recipient via the official **WhatsApp Cloud
API** (Meta), using an approved **template message**. The report itself
stays behind authentication: the message only carries a link to the
authenticated close detail in the AFORO web app.

**Principle: a WhatsApp failure never undoes or blocks a Cierre Diario.**

## Architecture

```
POST /restaurants/{r}/day-closes
  └─ one DB transaction (CloseRestaurantDayAction)
       ├─ RestaurantDayClose (immutable snapshot)
       └─ RestaurantDayCloseDelivery kind=automatic  (pending | skipped)
  COMMIT
  └─ afterCommit → queue: SendDayCloseWhatsApp(delivery_id)
        └─ WhatsAppProvider (MetaWhatsAppCloudApi)
             POST https://graph.facebook.com/{version}/{phone-number-id}/messages
             → accepted + provider_message_id   (accepted ≠ delivered)

Meta ──► POST /api/v1/webhooks/whatsapp  (X-Hub-Signature-256)
          └─ DeliveryStatusUpdater: sent → delivered → read | failed
```

- **One central sender.** AFORO owns one WhatsApp Business Account (WABA)
  and one sender phone number. Restaurants do not connect their own WABA in
  this version; they only choose a recipient.
- **Credentials live only in the environment** — never in the database,
  API responses, logs or the frontend.
- **The message uses only persisted data**: the RestaurantDayClose columns,
  its `report` snapshot and the recipient snapshot stored on the delivery.
  Changing products, settings or the recipient later never rewrites a
  message that was already queued.
- **No HTTP call ever runs inside a database transaction.**

## Environment variables

| Variable | Purpose |
|---|---|
| `WHATSAPP_CLOUD_ENABLED` | `true` to send. `false` (default): no delivery is attempted. |
| `WHATSAPP_GRAPH_API_VERSION` | Explicit Graph API version, e.g. `v25.0`. Never "latest" — upgrading is a deliberate change. |
| `WHATSAPP_PHONE_NUMBER_ID` | ID of AFORO's sender phone number (WhatsApp Manager / App Dashboard). |
| `WHATSAPP_ACCESS_TOKEN` | **System user** access token with `whatsapp_business_messaging` (+ `whatsapp_business_management`, `business_management`). Secret. |
| `WHATSAPP_WABA_ID` | AFORO's WABA id. Used to ignore webhooks from any other account. |
| `WHATSAPP_TEMPLATE_NAME` | Name of the approved template (see below). |
| `WHATSAPP_TEMPLATE_LANGUAGE` | Template language code, default `es_ES`. |
| `WHATSAPP_WEBHOOK_VERIFY_TOKEN` | A random string of your choosing, also entered in the App Dashboard. Secret. |
| `META_APP_SECRET` | The Meta App secret — validates `X-Hub-Signature-256`. Secret. |
| `AFORO_WEB_URL` | Base URL of the AFORO web app, e.g. `https://app.aforo.es`. |
| `AFORO_DAY_CLOSE_REPORT_PATH` | Authenticated detail route, default `/app/day-close/{id}`. **Must match the frontend route.** |

If `WHATSAPP_CLOUD_ENABLED=true` but any required value is missing or the
Graph version is malformed, closes still succeed and the automatic delivery
is recorded as `skipped` with `failure_code=configuration_incomplete`.

## Meta setup (external, one-time)

1. **Business portfolio** in Meta Business Manager (verified business
   recommended for production limits).
2. **WhatsApp Business Account (WABA)** for AFORO.
3. **Sender phone number** added and **registered** on the WABA (Cloud API).
   Note its *Phone number ID*.
4. **Meta App** (type Business) with the WhatsApp product.
5. **System user** with access to the app and the WABA; generate a
   long-lived token with `business_management`,
   `whatsapp_business_management` and `whatsapp_business_messaging`.
6. **Template** created and approved (below).
7. **Webhook**:
   - Callback URL: `https://<api-host>/api/v1/webhooks/whatsapp` — public
     HTTPS with a valid certificate (Meta does not accept self-signed).
   - Verify token: the value of `WHATSAPP_WEBHOOK_VERIFY_TOKEN`.
   - Subscribe to the **`messages`** field (it carries the status updates).
8. **Subscribe the app to the WABA**:
   `POST https://graph.facebook.com/{version}/{WABA_ID}/subscribed_apps`
   with `Authorization: Bearer <token>` (answers `{"success": true}`).

## Template contract

Create it in **WhatsApp Manager → Message templates**:

- Category: **Utility** (it is a transactional report the recipient opted
  into — not marketing).
- Language: **Spanish (Spain)** → `es_ES` (must equal
  `WHATSAPP_TEMPLATE_LANGUAGE`).
- Parameter format: **Named**.
- Body (example — Meta may ask for wording changes; the variable NAMES are
  the contract):

```
AFORO · Cierre diario
{{restaurant}} · {{business_date}}

Cobrado: {{total}}
Efectivo: {{cash}} · Tarjeta: {{card}} · Otros: {{other}}
Diferencia de caja: {{cash_difference}}

Pedidos: {{orders}} · Comensales: {{guests}} · Ticket medio: {{average_ticket}}
Más vendido: {{top_product}}

Atención: {{critical_reviews}} reseñas críticas · {{delays}} retrasos graves · {{unavailable_products}} productos no disponibles

Cerrado por {{closed_by}}
Informe completo: {{report_url}}
```

| Variable | Example | Source (persisted close) |
|---|---|---|
| `restaurant` | `AFORO Ruzafa` | report.summary.restaurant.name |
| `business_date` | `02/10/2026` | business_date |
| `total` | `1.867,50 €` | total_received |
| `cash` | `25,00 €` | cash_received |
| `card` | `1.842,50 €` | card_received |
| `other` | `0,00 €` | other_received |
| `cash_difference` | `−2,00 €` / `+3,00 €` | cash_difference |
| `orders` | `2` | orders_valid |
| `guests` | `4` | guests |
| `average_ticket` | `933,75 €` | average_ticket |
| `top_product` | `Paella (24)` / `Sin ventas registradas` | report.summary.top_product |
| `critical_reviews` | `0` | critical_feedback_count |
| `delays` | `0` | delays_count |
| `unavailable_products` | `0` | unavailable_products_count |
| `closed_by` | `Elena Ferrer` | closed_by_name_snapshot |
| `report_url` | `https://app.aforo.es/app/day-close/42` | AFORO_WEB_URL + AFORO_DAY_CLOSE_REPORT_PATH |

Values are always single-line plain text (no newlines/tabs, collapsed
spaces, ≤200 chars), es-ES formatted, in the close's own currency (never
converted). No customer data, no comments, no staff list. The link opens
the **authenticated** detail — there is no public report URL.

Changing variable names/count requires a new approved template.

## Statuses

| Status | Meaning |
|---|---|
| `pending` | Delivery created, waiting for the job. |
| `accepted` | Meta's HTTP API accepted the request and returned a message id. **Not** delivered. |
| `sent` / `delivered` / `read` | From Meta status webhooks. Forward-only; out-of-order/duplicate webhooks never regress the status. |
| `failed` | Provider error, failed webhook, or unknown outcome (see retry policy). `failure.code` / `failure.reason` (sanitized, phone numbers redacted). |
| `skipped` | Automatic delivery not attempted: `configuration_incomplete`, `recipient_missing` or `consent_missing`. Visible in the close's delivery history. |

When the restaurant switch is **off**, no delivery row is created at all.

## Retry policy (at-most-once)

The Cloud API has no idempotency key, so a duplicate WhatsApp message is
treated as worse than a missed one (which a manual resend fixes):

- Transaction boundaries: **claim** (short transaction: row lock,
  validate, set `sending_started_at` + `attempts+1`, COMMIT) → **HTTP call
  to Meta with no transaction and no row lock held** → **record** (new
  short transaction).
- A run that finds a pending delivery whose claim is **younger than 60s**
  (`CLAIM_STALE_AFTER_SECONDS`, above the 5s+10s HTTP timeouts) knows
  another execution is mid-call and exits without touching it. A claim
  **older** than that belongs to a run that died mid-call (e.g. Meta
  accepted but the process died before saving the message id): the outcome
  is unknown, so the delivery becomes `failed` (`unknown_outcome`) and is
  never re-sent automatically. A delivery with a `provider_message_id` is
  never sent again.
- **Meta answered with an error** → nothing was accepted. Codes Meta
  documents as transient (`4`, `80007`, `130429`, `131000`, `131016`,
  `131056`) and connection failures that never reached Meta (cURL 6/7) are
  retried after 60s and 300s, max 3 attempts. Before the retry the claim
  is released (`sending_started_at=null`, status stays `pending`,
  `attempts` kept), so the next run claims it again normally. A later
  success clears the transient `failure_code`. Everything else → `failed`.
- **Timeout / 5xx without an error body / 2xx without message id** →
  outcome unknown → `failed` with `unknown_outcome:*`, **never retried**.
- Timeouts: connect 5s, total 10s.

## Manual resend

`POST /api/v1/day-closes/{id}/deliveries` with `Idempotency-Key`
(`view_daily_closes`). Always a new `manual_resend` delivery to the
**current** active recipient (with valid consent); refused while any
delivery of that close is still `pending` (409), throttled to 3/min per
user and close. Audited as `day_close.whatsapp_resend_requested`.

## Recipient & consent

`GET|PUT /api/v1/restaurants/{r}/day-close-whatsapp-settings`
(`manage_restaurants`). One active recipient per restaurant; strict E.164
(`+34612345678`). The phone is encrypted at rest (APP_KEY), compared via a
keyed HMAC hash, and only ever shown masked (`+34 ••• •• 56 78`). A new or
changed number requires the admin to confirm: *"Confirmo que esta persona
ha aceptado recibir los cierres diarios por WhatsApp."* (`declared_by_admin`,
text `v1`); consent never migrates to another number.

Key dependencies:

| Column | Mechanism | Key |
|---|---|---|
| `phone_e164` (recipients and delivery snapshots) | Laravel `encrypted` cast | `APP_KEY` (decryption also tries `APP_PREVIOUS_KEYS`) |
| `phone_hash` | Deterministic HMAC-SHA256 of the E.164 number, used only for comparison ("same number?") | `APP_KEY` (current key only) |

> **Production Readiness item — key rotation.** `phone_hash` depends on the
> current `APP_KEY`: after a rotation, existing hashes no longer match and a
> recipient whose number is re-entered unchanged is treated as a NEW number
> (new consent required). Encrypted phones stay readable only while the old
> key is listed in `APP_PREVIOUS_KEYS`. A rotation strategy (re-encrypt +
> re-hash, or a dedicated hashing secret) is still to be defined.

## Queue worker (required)

Deliveries are sent by a queued job (`QUEUE_CONNECTION=database`). In
production a worker **must** be running, e.g.:

```
php artisan queue:work --queue=default --tries=3
```

(supervised by systemd/supervisor). Without a worker, deliveries stay
`pending`. The same worker also processes realtime broadcasts.

## Testing

- The automated suite never calls Meta (`Http::fake`).
- Manual end-to-end test with a Meta **test number**: set the env vars
  above with test credentials, add your own phone as an allowed test
  recipient in the App Dashboard, configure it as the restaurant
  recipient, enable, run `php artisan queue:work`, close a day.
- Webhooks locally require a public HTTPS tunnel to the API.

## Troubleshooting

| Symptom | Check |
|---|---|
| Deliveries `skipped` / `configuration_incomplete` | Missing env var or malformed `WHATSAPP_GRAPH_API_VERSION` (`v25.0`). |
| Stuck in `pending` | No queue worker running. |
| `failed` `132001` | Template name/language not found or not approved. |
| `failed` `132000` / `132018` | Template variables don't match the contract above. |
| `failed` `190` / `0` | Access token invalid/expired — use a system user token. |
| `failed` `131026` | Recipient not on WhatsApp / can't receive. |
| `failed` `unknown_outcome:*` | Timeout or 5xx: check WhatsApp manually; use a manual resend if needed. |
| `accepted` but never `sent` | Webhook not configured, app not subscribed to the WABA, or signature failing (wrong `META_APP_SECRET`). |
| Webhook 403 | Signature invalid (`META_APP_SECRET`) or verify token mismatch. |
