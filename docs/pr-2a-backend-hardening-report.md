# PR-2A — BACKEND HARDENING REPORT

## GIT

- HEAD: `e79a323` — fix: keep daily close product summary together in PDF.
- Branch: `main`, tracking `origin/main` at the same commit.
- Initial clean: YES. Status, branches and last 15 commits checked before edits.

## DEPENDENCIES

| Package | Before | After |
| --- | --- | --- |
| laravel/framework | 13.25.0 | 13.30.0 |
| league/commonmark | 2.10.0 | 2.10.2 |
| league/flysystem | 3.35.2 | 3.35.3 |

- Composer audit before: 4 advisories in 3 packages (Laravel 1, CommonMark 2, Flysystem 1).
- Composer audit after: 0 known advisories; no abandoned packages.
- Updated only these three packages with temporary exact constraints,
  `--with-all-dependencies --minimal-changes`, after reviewing a dry run.
- No other locked package version changed. Laravel root constraint now `^13.30`.
- No breaking change detected by focused tests; full-suite result below.

## PHP

- Host baseline: PHP 8.4.24; Composer 2.7.1 (emits deprecation notices on PHP 8.4).
- Application Sail runtime: PHP 8.5.9; Composer 2.10.2.
- composer.json before: `^8.3`; after: `^8.4.1`.
- Effective minimum: PHP 8.4.1, required by the unchanged Symfony 8.1 packages.
- check-platform-reqs: PASS in Sail, including ext-curl.
- Host check: FAIL for missing ext-curl; PHP itself satisfies the requirement.
  The controlled update ran in Sail without ignoring platform requirements.

## DATABASE SEEDER

- Previous risk: `test@example.com` was created/reset to a known password in every environment.
- Correction: explicit `local`/`testing` guard before the sample user; existing
  PlatformAdminDevSeeder already has the same environment guard.
- DatabaseSeeder usages audited: definition and existing development/admin-seeder
  references; no application provisioning path invoking the whole seeder found.
- Production user creation possible through DatabaseSeeder or the new command: NO.
- Tests prove staging/production preserve the existing user's password and do not
  create another user. Local sample-user workflow still works.

## PROVISIONING

- Command: `php artisan aforo:provision`.
- Seeders: RoleSeeder, PermissionSeeder, RolePermissionSeeder, PlatformRoleSeeder,
  PlatformPermissionSeeder, PlatformRolePermissionSeeder, in this order.
- Transaction: YES. All six perform DML on the same default connection; no DDL,
  external effects or user factories. Failure test proves full rollback.
- Exceptions propagate; subprocess test proves a nonzero CLI exit on failure.
- Idempotent: YES, two consecutive runs against the PostgreSQL testing database.
- Second run result: identical rows, IDs, timestamps and permission matrices.
- Users created: 0. QA/demo data created: 0. No organization, restaurant, product
  or user/platform-role assignment created.

## PERMISSIONS

- Matrix preserved: YES, all tenant and platform role matrices checked.
- Provisioning adds missing expected grants.
- Revocation behavior: additive `syncWithoutDetaching` unchanged. Legacy/custom
  grants remain; restoration/preservation tested in both permission systems.
- No immediate new security issue found that requires changing ACL behavior.
  Existing obsolete grants require an explicit separate review/revocation.

## ENV CONTRACT

- `.env.example` remains a secret-free local template with staging/production notes.
- PostgreSQL: explicit pgsql, host/port/database/username placeholders; local
  SQLite alternative documented. Actual `.env` unchanged.
- SSL: DB_SSLMODE=prefer locally; require/verify-full documented for remote environments.
- Sessions: secure-cookie setting explicit, true required on HTTPS; HTTP-only true,
  SameSite lax, configurable SESSION_DOMAIN.
- APP_PREVIOUS_KEYS: commented rotation placeholder; no actual key changed.
- Queue/logs: existing drivers unchanged; retry_after, failed driver, timeout,
  worker restart, level, destination and daily retention documented.
- APP_DEBUG safety: documented deployment gate requires effective cached
  APP_DEBUG=false in staging/production; no runtime crash or new framework.

## CORS

- Before: two hardcoded localhost origins.
- After: explicit comma-separated CORS_ALLOWED_ORIGINS; trim and HTTP(S) origin
  validation. Empty by default outside local/testing; previous local defaults retained.
- Wildcard possible with credentials: NO. Wildcard/malformed entries discarded;
  origin patterns empty. Credentials remain true.
- Config tests cover defaults, explicit list, wildcard rejection; HTTP preflight
  tests cover allowed and untrusted origins.

## SANCTUM

- Auth model unchanged.
- Stateful domain config: SANCTUM_STATEFUL_DOMAINS, hostnames with optional ports.
- Secure cookie config: SESSION_SECURE_COOKIE=true on HTTPS, HTTP_ONLY=true, lax default.
- Session domain config: environment-specific SESSION_DOMAIN, null for host-only.
- Environment parsing tested with placeholder domains; no real domain hardcoded.

## OPENAPI

- Previously public L5 Swagger UI/specification/assets/OAuth callback routes audited.
- Production exposure default: disabled, 404, also in staging.
- Local behavior: enabled by default in local/testing; UI response tested.
- ENV-driven setting: L5_SWAGGER_ENABLED; group middleware protects every route,
  independent of runtime route registration. Config caches must be rebuilt on changes.
- Explicit true enables public docs intentionally; use only in a controlled environment.

## ROOT

- GET / behavior: 404. Existing root test updated.
- /up remains healthy; API/auth tests pass.

## TESTS

- Targeted: 70 passed, 323 assertions (Provisioning, Configuration, Permissions, Auth).
- Full suite: PASS — 1,853 tests passed in 494.625 seconds (Sail/PostgreSQL).
- Assertions: 6,840 in the full suite.
- Pint: PASS (`./vendor/bin/sail bin pint --dirty --test`).
- Composer validate: PASS.
- Audit: PASS, 0 known advisories.
- Platform requirements: PASS in Sail; host missing ext-curl as recorded above.
- git diff --check: PASS.

## FILES CHANGED

Tracked modifications:

- .env.example
- composer.json
- composer.lock
- config/cors.php
- config/l5-swagger.php
- database/seeders/DatabaseSeeder.php
- routes/web.php
- tests/Feature/ExampleTest.php

New files (unstaged):

- app/Console/Commands/Provision.php
- app/Http/Middleware/EnsureDocumentationEnabled.php
- docs/staging-production-contract.md
- docs/pr-2a-backend-hardening-report.md
- tests/Feature/Configuration/EnvironmentContractTest.php
- tests/Feature/Configuration/ExposureTest.php
- tests/Feature/Provisioning/ProvisionTest.php

`git diff --stat` (tracked files only; new files listed above):

```text
 .env.example                        | 36 ++++++++++++++++++++++------
 composer.json                       |  4 ++--
 composer.lock                       | 48 ++++++++++++++++++-------------------
 config/cors.php                     | 15 ++++++++----
 config/l5-swagger.php               |  7 +++++-
 database/seeders/DatabaseSeeder.php |  4 +++-
 routes/web.php                      |  2 +-
 tests/Feature/ExampleTest.php       |  4 ++--
 8 files changed, 78 insertions(+), 42 deletions(-)
```

## BUGS/ISSUES FOUND

- Four dependency advisories and unguarded sample-user creation: corrected.
- Inaccurate PHP minimum, hardcoded CORS, public Swagger and default welcome page: corrected.
- Host PHP missing ext-curl / old Composer deprecation notices: environment limitation;
  validation and dependency update completed on the fully compatible Sail runtime.
- An intermediate focused run overlapped a full-suite run against the same testing
  database. The full run was terminated and checks rerun sequentially. No development
  database was migrated, seeded or otherwise destructively modified.
- Initial test assumptions corrected: the CORS library emits the one fixed allowed
  origin even for an untrusted request (browsers reject the mismatch); rejection test
  uses multiple origins. Environment config tests use an isolated mutable repository
  because Laravel's immutable env repository does not overwrite existing variables.

## PRODUCTION READINESS REMAINING

Provision infrastructure/PostgreSQL, inject real secrets and approved domains,
verify HTTPS/SSL/cached debug gate, perform migrations and ACL provisioning,
create production accounts via an approved onboarding flow, supervise workers,
configure backups and monitoring, and review obsolete grants. Hosting, Supabase,
Meta, production Reverb, S3 and deployment remain outside this card.

- COMMIT: NO.
- PUSH: NO.
- DEPLOY: NO.
- Supabase/Hostinger/Meta configured: NO.
- Local APP_KEY / actual .env changed: NO.

VERDICT: PR-2A READY FOR COMMIT.
