# Backend staging/production contract

Runtime: PHP >=8.4.1 within PHP 8.x, with `composer check-platform-reqs`
passing on the target runtime. Local Sail currently runs PHP 8.5.
Install the committed lock with `composer install`; audit it before promotion.

## Configuration gate before deployment

Set `APP_ENV=staging` or `production`, `APP_DEBUG=false`, and an HTTPS `APP_URL`.
Verify the effective cached `config('app.env')` and `config('app.debug')` before
serving traffic. Laravel reads debug configuration; this application deliberately
uses a deployment gate instead of crashing on boot. Do not promote if debug is true.
Keep APP_KEY in the environment secret store. For key rotation only,
`APP_PREVIOUS_KEYS` accepts comma-separated previous keys; never commit them.
Rebuild config/route caches after environment changes, and restart queue workers.

Use PostgreSQL (`DB_CONNECTION=pgsql`) with environment-specific host, port,
database and credentials. Set `DB_SSLMODE=require`, or `verify-full` with a
trusted CA and matching hostname. `.env.example` is a local Sail template;
its database password is intentionally empty. Local SQLite is optional outside Sail.
Do not run migrations or provisioning against an unintended database.

Keep the existing database queue/session/cache drivers. Configure worker supervision,
failed-job monitoring and a worker timeout lower than `DB_QUEUE_RETRY_AFTER`
(default 90 seconds). Select `LOG_STACK=stderr` or `daily` for the hosting runtime,
`LOG_LEVEL=info`, and retention via `LOG_DAILY_DAYS`. Do not log secrets.

## SPA authentication and CORS

The Sanctum SPA/session authentication model is unchanged.
`SANCTUM_STATEFUL_DOMAINS` is an explicit list of SPA hostnames (include ports
when needed; no schemes). `CORS_ALLOWED_ORIGINS` is an explicit comma-separated
list of HTTP(S) origins including scheme and optional port. Whitespace is trimmed;
wildcards and malformed entries are discarded. No matching origin means no CORS
grant. If unset, only local/testing receive the existing localhost defaults.
Credentials remain enabled. Supply the actual staging/production frontend origins.

Set `SESSION_SECURE_COOKIE=true` on HTTPS, `SESSION_HTTP_ONLY=true`, and
`SESSION_SAME_SITE=lax` by default. Set `SESSION_DOMAIN` for the environment's
cookie scope (or leave null for host-only cookies). SPA and API should share the
same site for the default lax cookie contract. Do not hardcode a real domain.

## Provisioning

After migrations, run `php artisan aforo:provision`. It invokes exactly:
RoleSeeder, PermissionSeeder, RolePermissionSeeder, PlatformRoleSeeder,
PlatformPermissionSeeder and PlatformRolePermissionSeeder, in one transaction.
It creates no users, restaurants, factories, demo or QA records. Failures propagate
with a nonzero CLI exit and roll back all six seeders. Repeated runs preserve the
matrix without duplicating records. Execute serially during provisioning.

Grants are additive (`syncWithoutDetaching`): missing expected grants are added;
old/custom grants are never automatically revoked. Review and explicitly revoke
obsolete grants separately; this command is not an ACL revocation mechanism.
`DatabaseSeeder` retains local/testing users for development; its sample account
and PlatformAdminDevSeeder cannot create users in staging/production.

## Public surface

`GET /` returns 404; `/up` and API routes remain available.
Swagger UI, JSON/YAML, assets and OAuth callback return 404 by default outside
local/testing. `L5_SWAGGER_ENABLED=false` explicitly disables them; setting true
intentionally exposes all those routes, so only enable in a controlled environment.
Generated specifications remain under storage, never copy them into public storage.

No Supabase, hosting, Meta, production Reverb, S3 or deployment is configured here.
Infrastructure, secret injection, production account onboarding, migrations,
worker supervision, backups and promotion gates remain subsequent work.
