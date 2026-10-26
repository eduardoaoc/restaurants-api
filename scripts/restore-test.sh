#!/usr/bin/env bash
# Restore a pg_dump (custom format) into a DISPOSABLE PostgreSQL container
# and verify it. Never touches the Sail database, staging or production.
#
#   scripts/restore-test.sh /path/to/backup.dump
#
# Isolation guarantees (checked, not assumed):
#   - a new container named aforo-restore-test-<timestamp>, image
#     postgres:18-alpine (same major as Sail), started with --network none
#     and no published port: nothing outside the container can reach it,
#     and it cannot reach any other database;
#   - the target database is created inside that container and its
#     identity (container hostname, database name, empty pg_stat_activity
#     of other clients) is printed BEFORE pg_restore runs;
#   - the dump is copied in read-only; the original file is never modified;
#   - the container is removed on exit (also on failure).
# Prints only counts and object names — never row contents.
set -euo pipefail

dump="${1:?usage: scripts/restore-test.sh /path/to/backup.dump}"
[ -r "$dump" ] || { echo "Cannot read $dump" >&2; exit 1; }

name="aforo-restore-test-$(date +%Y%m%d%H%M%S)"
password="$(head -c 24 /dev/urandom | base64 | tr -dc 'A-Za-z0-9')"
cleanup() { docker rm -f "$name" >/dev/null 2>&1 || true; }
trap cleanup EXIT

echo "dump: $(basename "$dump") ($(stat -c %s "$dump") bytes, sha256 $(sha256sum "$dump" | cut -c1-16)…)"
docker run -d --rm --name "$name" --network none \
  -e POSTGRES_PASSWORD="$password" -e POSTGRES_DB=restore_test \
  postgres:18-alpine >/dev/null

for _ in $(seq 1 60); do
  docker exec "$name" pg_isready -q -U postgres -d restore_test 2>/dev/null && break
  sleep 1
done
docker exec "$name" pg_isready -q -U postgres -d restore_test

psqlc() { docker exec -i "$name" psql -v ON_ERROR_STOP=1 -U postgres -d restore_test -Atc "$1"; }

echo "target: container=$name network=none ports=$(docker port "$name" | wc -l) db=$(psqlc 'select current_database()') other_clients=$(psqlc "select count(*) from pg_stat_activity where datname = current_database() and pid <> pg_backend_pid() and backend_type = 'client backend'")"
[ "$(psqlc 'select current_database()')" = "restore_test" ] || { echo "Unexpected target database" >&2; exit 1; }

docker cp "$dump" "$name:/tmp/backup.dump"
expected_tables="$(docker exec "$name" pg_restore --list /tmp/backup.dump | grep -c ' TABLE DATA ')"

docker exec "$name" pg_restore --no-owner --no-privileges --exit-on-error -U postgres -d restore_test /tmp/backup.dump

restored_tables="$(psqlc "select count(*) from information_schema.tables where table_schema = 'public' and table_type = 'BASE TABLE'")"
echo "TABLE DATA entries in dump: $expected_tables; tables restored: $restored_tables"
echo "migrations rows: $(psqlc 'select count(*) from migrations'); max batch: $(psqlc 'select max(batch) from migrations')"
for t in organizations restaurants users invitations restaurant_day_closes restaurant_day_close_deliveries jobs failed_jobs; do
  if [ "$(psqlc "select to_regclass('public.$t') is not null")" = "t" ]; then
    echo "  $t: $(psqlc "select count(*) from public.$t") rows"
  else
    echo "  $t: (absent in dump)"
  fi
done

[ "$restored_tables" -ge "$expected_tables" ] || { echo "Fewer tables restored than TABLE DATA entries" >&2; exit 1; }
echo "RESTORE OK (container removed on exit)"
