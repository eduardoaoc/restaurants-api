#!/usr/bin/env bash
# Reproducible, isolated backend test run inside the existing Sail dev
# container (PHP 8.5 with gd/bcmath/pdo_pgsql/pdo_sqlite — no temporary
# extensions). See docs/testing-isolated.md.
#
#   scripts/test-sail.sh                          # whole suite
#   scripts/test-sail.sh tests/Feature/Invitations
#
# Isolation guarantees:
#   - database: phpunit.xml's DB_DATABASE=testing (Sail's own test database
#     on the dev Postgres; tests/TestCase.php refuses any database not named
#     `testing`/`*_testing`);
#   - no cached configuration may exist (it would override phpunit.xml);
#   - storage (logs, cache, sessions, fake disks) under a container-only
#     directory recreated on every run, so no test writes the application's
#     storage/logs/laravel.log or root-owned storage folders;
#   - mail=array, queue=sync, broadcast=null, cache/session=array (phpunit.xml).
set -euo pipefail
cd "$(dirname "$0")/.."

if [ -f bootstrap/cache/config.php ]; then
  echo "Refusing to run: bootstrap/cache/config.php exists (would override phpunit.xml). Run 'php artisan config:clear' deliberately first." >&2
  exit 1
fi

docker compose exec -T -u sail \
  -e LARAVEL_STORAGE_PATH=/tmp/aforo-testing-storage \
  -e APP_CONFIG_CACHE=/tmp/aforo-testing-no-config-cache.php \
  laravel.test bash -c '
    set -euo pipefail
    rm -rf /tmp/aforo-testing-storage /tmp/aforo-testing-no-config-cache.php
    mkdir -p /tmp/aforo-testing-storage/{app/public,framework/{cache/data,sessions,views,testing},logs}
    echo "storage=$LARAVEL_STORAGE_PATH php=$(php -r "echo PHP_VERSION;")"
    exec php vendor/bin/phpunit "$@"
  ' -- "$@"
