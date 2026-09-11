# Realtime (Bloco 7)

Laravel Reverb broadcasts small, incremental notifications on top of the
REST API. REST remains the only canonical source of state — realtime is
best-effort delivery, never a second way to mutate the domain.

## Architecture

```
REST  ──────────────────────────────────────────────► canonical state
GET /api/v1/restaurants/{restaurant}/operations/live  ► full snapshot
Reverb / WebSocket ─────────────────────────────────► incremental events only
Reconnect / any doubt about consistency ────────────► GET /operations/live again
```

The client must stay correct even if Reverb is completely unavailable, a
socket drops, an event is lost, or the app goes offline for a while.
Realtime only improves latency/UX; it is never required for correctness.

Recommended client flow (documented here for the future frontend
integration — **not implemented in this block**, see item 39/87):

1. Authenticate (Sanctum session cookie, as already used everywhere else).
2. `GET /operations/live` — full snapshot.
3. Subscribe to the private `restaurant.{id}` channel.
4. Apply incoming events incrementally.
5. On reconnect (or any doubt about consistency) — refetch the snapshot.

There is a small unavoidable race between step 2 and step 3 (a change
could happen in between and be missed). This block does not add an event
log/replay mechanism to close that window (explicitly out of scope — see
item 54/88); the practical mitigation for the frontend later is either to
subscribe *before* fetching the snapshot, or to immediately refetch the
snapshot right after subscribing succeeds.

For **structural** events (`table.session.transferred`,
`floor_plan.updated`) the recommended client reaction is a full refetch of
`/operations/live` rather than trying to patch local state — the payload
is intentionally too small to reconstruct the new state from. For
**simple** events (`staff.shift.started`, `order.status_changed`, ...) a
local patch is enough.

## No second backend inside the socket

Every mutation still goes through its existing REST endpoint → Action →
`DB::transaction()` → commit. Events are dispatched from inside that same
transaction, as the very last step before `return`, in the same Action
that owns the mutation — never from a Controller, never from a generic
Model observer (see item 66). See "Event catalog" below for the exact
Action → Event mapping.

## Reverb setup

- `laravel/reverb` installed via Composer (already the project's own
  broadcaster, not a second WebSocket process/framework — item 89).
- `config/broadcasting.php` — Laravel's standard config, `reverb`
  connection added automatically when the package is required.
- `config/reverb.php` — Reverb's own config; `apps.apps.0.allowed_origins`
  holds **bare hostnames only** (`localhost`, `127.0.0.1` — no scheme, no
  port). Reverb's `verifyOrigin()` extracts just the host from the
  browser's `Origin` header (`parse_url($origin, PHP_URL_HOST)`) and
  matches it against this list with `Str::is()`; a scheme-qualified entry
  like `http://localhost:5173` never matches and the handshake is
  rejected with `InvalidOrigin` (Bloco 1.3B — this exact mismatch broke
  the real browser handshake until fixed here). Never `*`. Overridable
  via `REVERB_ALLOWED_ORIGINS` (comma-separated hostnames) — production
  must set this to its real authorized hosts, still no wildcard.
- `routes/channels.php` — one private channel definition.
- `bootstrap/app.php` — `->withBroadcasting(routes/channels.php, [...])`,
  registered as its own explicit call (not via `withRouting()`'s
  shorthand) so `/broadcasting/auth` runs the exact same auth stack as
  every other tenant endpoint.

## Channel

One private channel per Restaurant:

```
private-restaurant.{restaurantId}
```

(`Broadcast::channel('restaurant.{restaurantId}', ...)` in
`routes/channels.php` — Laravel/Pusher/Reverb convention adds the
`private-` wire prefix automatically; `new PrivateChannel("restaurant.{id}")`
on the PHP side.)

No global operations channel, no cross-restaurant channel, no Platform
Super Admin bypass (see Security below).

### Authorization rule

A user may subscribe to `restaurant.{id}` if and only if:

- they are authenticated (`auth:sanctum` + `active_user` on the
  `/broadcasting/auth` route — a guest gets 401 before ever reaching the
  channel closure);
- the restaurant's Organization is not suspended;
- they belong to that Organization (`organizations()->whereKey(...)`);
- the restaurant is within their own `RestaurantScope`
  (`RestaurantScope::canAccessRestaurant()`).

This is deliberately the **same reachability rule as
`RestaurantPolicy::view()`** — not `view_operations`/`view_reports`. A
waiter/kitchen/cashier who cannot open the administrative dashboard must
still receive the operational events relevant to the restaurant they work
at (a future KDS, a future waiter app). Opening a dashboard and receiving
operational events are two different concerns.

### `/broadcasting/auth`

Registered with middleware `['api', 'auth:sanctum', 'active_user']` (the
`api` group is what gives it Sanctum's `EnsureFrontendRequestsAreStateful`
+ `SubstituteBindings`, the same as every other tenant endpoint — the
route lives outside the `routes/api.php` `api/v1` prefix, so it does not
get that group automatically the way `routes/api.php` does).

`config/cors.php`'s `paths` includes `broadcasting/auth` explicitly (it
is not under `api/*`) so the existing dev origins
(`http://localhost:5173`, `:5174`, `:8080`) can call it with credentials,
exactly like `sanctum/csrf-cookie` already does. No Bearer token, no
separate realtime credential — this route uses the identical Sanctum SPA
session-cookie mechanism as the rest of the API.

## Event envelope

Every event extends `App\Events\Realtime\RealtimeEvent`, which fixes a
shared envelope and appends the event's own small, explicit payload
(never raw Eloquent Model serialization — see `broadcastWith()`):

```json
{
  "event_id": "…uuid…",
  "schema_version": 1,
  "occurred_at": "2026-09-09T12:00:00.000000Z",
  "restaurant_id": 1,

  "…event-specific fields…": "…"
}
```

- `event_id` — UUID, unique per broadcast. Lets a future frontend ignore
  a duplicate delivery (queues/WebSockets can redeliver) without the
  backend needing a dedup table/log.
- `schema_version` — always `1` for now; a future breaking payload change
  bumps this rather than silently changing shape under the same name.
- `occurred_at` — ISO 8601 UTC. Never localized/formatted — the frontend
  converts to the restaurant's timezone (`RestaurantSettings.timezone`,
  same as everywhere else in this API) for display.
- `restaurant_id` — redundant with the channel name on purpose (cheap
  client-side sanity check, and useful if a future consumer fans events
  out across multiple channels).

`broadcastAs()` is the stable wire name a frontend can rely on (e.g.
`order.status_changed`) — never the PHP class/FQCN.

## Event catalog

| Event (wire name)             | Triggering Action                                              | Payload (beyond the envelope)                                                          |
|--------------------------------|------------------------------------------------------------------|------------------------------------------------------------------------------------------|
| `table.session.opened`         | `OpenTableAction`                                                | `table_id`, `table_session_id`, `guest_count`, `opened_at`                               |
| `table.session.closed`         | `CloseTableAction`                                               | `table_id`, `table_session_id`, `closed_at`                                              |
| `table.session.transferred`    | `TransferTableSessionAction`                                     | `table_session_id`, `from_table_id`, `to_table_id`                                       |
| `table.waiter.assigned`        | `AssignWaiterAction` (no previous waiter)                        | `table_session_id`, `table_id`, `previous_waiter_user_id` (null), `new_waiter_user_id`   |
| `table.waiter.reassigned`      | `AssignWaiterAction` (had a different waiter)                    | same fields, `previous_waiter_user_id` non-null                                          |
| `table.waiter.unassigned`      | `UnassignWaiterAction`                                           | same fields, `new_waiter_user_id` null                                                   |
| `staff.shift.started`          | `StartStaffShiftAction`                                          | `staff_shift_id`, `user_id`, `started_at`                                                |
| `staff.shift.ended`            | `EndStaffShiftAction`                                            | `staff_shift_id`, `user_id`, `ended_at`                                                  |
| `order.created`                | `OrderCreationService` (shared by customer_qr + waiter origin)   | `table_id`, `table_session_id`, `order_id`, `origin`, `status`, `created_at`              |
| `order.status_changed`         | `ApproveOrderAction`, `RejectOrderAction`, `TransitionOrderStatusAction` (accept/startPreparing/markReady/serve) | `table_id`, `table_session_id`, `order_id`, `previous_status`, `status`, `changed_at` |
| `table_request.created`        | `CreatePublicTableRequestAction`                                 | `table_id`, `table_session_id`, `table_request_id`, `type`, `status`                     |
| `table_request.acknowledged`   | `TransitionTableRequestStatusAction::acknowledge()`               | same fields                                                                               |
| `waiter_call.created`          | `CallResponsibleWaiterAction`                                    | `table_id`, `table_session_id`, `waiter_call_id`, `waiter_user_id`, `status`              |
| `waiter_call.acknowledged`     | `AcknowledgeWaiterCallAction`                                     | same fields                                                                               |
| `payment.recorded`             | `RecordPaymentAction` (never on an idempotency replay)            | `table_session_id`, `table_id`, `payment_id`, `amount`, `payment_method`, `recorded_at`   |
| `floor_plan.updated`           | `UpdateFloorPlanLayoutAction` (one event per bulk save)           | `tables_updated_count`, `table_ids`                                                      |

Deliberately **not** broadcast: `table_request.completed`/`.cancelled`
(spec scoped this to created/acknowledged only — see item 26/80),
anything from Analytics (item 30/81 — historical, REST-only), a full
`operations.live.updated` snapshot (item 31 — defeats the point of
incremental events), and any per-second "elapsed time"/clock-tick event
(item 60/61 — the frontend derives duration locally from the timestamps
it already has).

## After-commit guarantee

Every event implements both `ShouldBroadcast` (queued — see Queue below)
and `Illuminate\Contracts\Events\ShouldDispatchAfterCommit`. Laravel's
`Dispatcher` checks this interface and, when the dispatch call happens
inside an open `DB::transaction()`, defers running the event's listeners
(which is what queues the actual broadcast job) until that transaction
commits. A rolled-back transaction never broadcasts — a rolled-back
mutation can never announce a state that doesn't exist.

Proven directly in `tests/Feature/Realtime/AfterCommitBroadcastTest.php`,
using a real `Event::listen()` spy (not `Event::fake()` — a fake would
intercept dispatch immediately and never exercise this deferral at all).

## Failure semantics

`ShouldBroadcast` (not `ShouldBroadcastNow`) means every broadcast is
queued: Laravel wraps the event in an internal `BroadcastEvent` job and
pushes it onto the configured queue connection. The actual call to Reverb
happens later, inside a queue worker process — never inline during the
HTTP request.

This is the deliberate choice for item 37/79: if Reverb is unreachable,
only the queued job fails/retries (per the queue's normal failure
handling) — the HTTP request that triggered the mutation has already
returned its normal, successful response by then. `ShouldBroadcastNow`
was considered and rejected: it would call the broadcaster synchronously
inside the request, so a Reverb outage could turn an otherwise-successful
`POST /orders/{id}/ready` into a 500 — exactly what item 37 forbids.

Queue connection: whatever `QUEUE_CONNECTION` already is for the app (no
new connection introduced) — `database` in this project's `.env` (Redis
is available and works too; nothing here requires switching). No new
migration was needed — the `jobs` table already existed unused in this
project.

## Security checklist

- Private channel only (`PrivateChannel`), never a public/global channel.
- Same Sanctum SPA session-cookie auth as the rest of the API — no Bearer
  token, no separate realtime credential (item 40).
- Channel authorization reuses `RestaurantScope` — an out-of-scope
  restaurant, a cross-Organization restaurant, and a suspended
  Organization are all denied (see `ChannelAuthorizationTest`).
- No Platform Super Admin bypass — a platform admin only passes this
  check if they also happen to be a real member of that Organization.
- Every payload is built by an explicit `broadcastWith()` — never a raw
  `Order`/`PaymentRecord`/... model serialization — so a field can never
  leak by accident through an added Eloquent attribute/relation later
  (see `EventPayloadContractTest`). `payment.recorded` never carries a
  card number, gateway token, or other payment credential.
- Reverb's own `allowed_origins` holds bare hostnames only (`localhost`,
  `127.0.0.1` in dev), never full URLs and never `*` — see Reverb setup
  above for why the host-only format is required.

## Local manual test

Two processes, same container:

```bash
# Terminal 1
./vendor/bin/sail up -d
# (first time after this block, or after editing compose.yaml/.env:
#  ./vendor/bin/sail down && ./vendor/bin/sail up -d
#  so the new Reverb port mapping in compose.yaml takes effect)

# Terminal 2
./vendor/bin/sail artisan reverb:start

# Terminal 3 — a queue worker, since broadcasting is queued (see above)
./vendor/bin/sail artisan queue:work
```

Then, from a 4th terminal, drive one simple domain action through its
real endpoint (any dev user/session works, e.g. the seeded owner):

```bash
curl -i -c /tmp/cookies -b /tmp/cookies http://localhost:8080/sanctum/csrf-cookie
curl -i -c /tmp/cookies -b /tmp/cookies -X POST http://localhost:8080/api/v1/auth/login \
  -H 'Content-Type: application/json' -H 'X-XSRF-TOKEN: <token from cookie>' \
  -d '{"email":"<dev owner email>","password":"<dev owner password>"}'

# open a table session — this is what fires table.session.opened
curl -i -c /tmp/cookies -b /tmp/cookies -X POST http://localhost:8080/api/v1/tables/<table_id>/open \
  -H 'Content-Type: application/json' -H 'X-XSRF-TOKEN: <token from cookie>' \
  -d '{"guest_count":2}'
```

Expected result: the `queue:work` terminal logs a processed
`Illuminate\Broadcasting\BroadcastEvent` job shortly after the `POST`
responds (not before — see After-commit guarantee), and the Reverb
terminal logs an outgoing message on `private-restaurant.<id>`. Without a
WebSocket client actually connected and subscribed, nothing appears in a
browser — this guide only confirms the pipeline fires end-to-end (queue
→ Reverb), which is this block's whole scope; connecting an actual
frontend client is explicitly deferred (item 39/87).

`php artisan channel:list` and `php artisan route:list --path=broadcasting`
are the quickest way to confirm the channel/route are registered at all
without driving a full request.
