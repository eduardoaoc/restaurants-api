#!/usr/bin/env bash
# AFORO API — staging deploy / rollback (release directories + atomic symlink).
# Runs ON THE STAGING SERVER as the unprivileged deploy user (never root).
# Runbook: docs/staging-runbook.md §4 (deploy) and §5 (rollback).
#
#   deploy.sh deploy   <git-ref> [--migrate] [--provision] [--dry-run]
#   deploy.sh rollback [--dry-run]
#   deploy.sh status
#
# Layout under $AFORO_ROOT (default /srv/aforo-staging/api):
#   repo.git/            bare mirror of the API repository (git clone --mirror)
#   shared/.env          staging environment (mode 600) — NEVER generated here
#   shared/storage/      persistent storage: logs, uploads (app/public), mail capture
#   releases/<id>/       one directory per deploy (code only)
#   current -> releases/<id>
#
# Safety rules enforced below:
#   - APP_KEY is never generated: an empty APP_KEY aborts the deploy;
#   - pending migrations abort unless --migrate is given (the SQL is shown
#     with --pretend and saved to shared/deploy-logs first);
#   - aforo:deploy-check must pass on the NEW release before the switch;
#   - only the aforo-staging supervisor group is restarted (no global
#     restart, no queue:restart, no pkill);
#   - no seeders, no demo data; provisioning (roles/permissions only,
#     additive) only with --provision;
#   - rollback switches code only: it never runs migrate:rollback.
set -euo pipefail

AFORO_ROOT="${AFORO_ROOT:-/srv/aforo-staging/api}"
PHP="${PHP:-php}"
COMPOSER="${COMPOSER:-composer}"
SUPERVISOR_GROUP="${SUPERVISOR_GROUP:-aforo-staging}"
SUPERVISORCTL="${SUPERVISORCTL:-sudo -n /usr/bin/supervisorctl}"
HEALTH_URL="${HEALTH_URL:-}"
KEEP_RELEASES="${KEEP_RELEASES:-5}"
DRY_RUN=0

log() { printf '[deploy %s] %s\n' "$(date -u +%H:%M:%S)" "$*"; }
die() { printf '[deploy] ERROR: %s\n' "$*" >&2; exit 1; }
run() { if [ "$DRY_RUN" = 1 ]; then printf '[dry-run] %s\n' "$*"; else "$@"; fi; }
env_value_present() { grep -Eq "^$1=.+" "$AFORO_ROOT/shared/.env"; }

preflight() {
  [ "$(id -u)" != 0 ] || die "refusing to run as root; use the deploy user"
  [ -d "$AFORO_ROOT/shared/storage" ] || die "missing $AFORO_ROOT/shared/storage"
  [ -f "$AFORO_ROOT/shared/.env" ] || die "missing $AFORO_ROOT/shared/.env (copy .env.staging.example and fill it)"
  [ "$(stat -c %a "$AFORO_ROOT/shared/.env")" = 600 ] || die "shared/.env must be mode 600"
  grep -Eq '^APP_ENV=staging$' "$AFORO_ROOT/shared/.env" || die "shared/.env is not APP_ENV=staging"
  grep -Eq '^APP_DEBUG=false$' "$AFORO_ROOT/shared/.env" || die "shared/.env must set APP_DEBUG=false"
  env_value_present APP_KEY || die "APP_KEY is empty: generate it ONCE (see runbook §3.2); this script never does"
  ! grep -Eq 'CHANGE_ME' "$AFORO_ROOT/shared/.env" || die "shared/.env still contains CHANGE_ME placeholders"
  "$PHP" -r 'exit(version_compare(PHP_VERSION, "8.4.1", ">=") ? 0 : 1);' || die "PHP >= 8.4.1 required"
}

link_shared() {
  local release="$1"
  run rm -rf "$release/storage"
  run ln -sfn "$AFORO_ROOT/shared/storage" "$release/storage"
  run ln -sfn "$AFORO_ROOT/shared/.env" "$release/.env"
}

artisan() { local release="$1"; shift; run "$PHP" "$release/artisan" "$@" --no-interaction; }

switch_to() {
  local release="$1"
  run ln -sfn "$release" "$AFORO_ROOT/current.next"
  run mv -Tf "$AFORO_ROOT/current.next" "$AFORO_ROOT/current"
  log "current -> $(basename "$release")"
}

restart_processes() {
  # Scoped to this app's supervisor group only.
  run $SUPERVISORCTL restart "$SUPERVISOR_GROUP:*"
}

health() {
  local release="$1"
  if [ -n "$HEALTH_URL" ]; then
    run curl --fail --silent --show-error --max-time 10 -o /dev/null "$HEALTH_URL" && log "health: $HEALTH_URL answered 2xx"
  else
    log "health: HEALTH_URL not set, skipping HTTP check"
  fi
  artisan "$release" aforo:queue-status
}

deploy() {
  local ref="$1"; shift
  local migrate=0 provision=0
  for arg in "$@"; do
    case "$arg" in
      --migrate) migrate=1 ;;
      --provision) provision=1 ;;
      --dry-run) DRY_RUN=1 ;;
      *) die "unknown option $arg" ;;
    esac
  done
  preflight

  local id release
  id="$(date -u +%Y%m%d%H%M%S)"
  release="$AFORO_ROOT/releases/$id"
  [ -d "$AFORO_ROOT/repo.git" ] || die "missing $AFORO_ROOT/repo.git (git clone --mirror <repo> repo.git)"

  log "fetching and exporting $ref into releases/$id"
  run git -C "$AFORO_ROOT/repo.git" fetch --prune origin
  local commit
  commit="$(git -C "$AFORO_ROOT/repo.git" rev-parse --verify "$ref^{commit}")" || die "unknown ref $ref"
  run mkdir -p "$release"
  if [ "$DRY_RUN" = 1 ]; then
    printf '[dry-run] git archive %s | tar -x -C %s\n' "$commit" "$release"
  else
    git -C "$AFORO_ROOT/repo.git" archive "$commit" | tar -x -C "$release"
    printf '%s\n' "$commit" > "$release/REVISION"
  fi

  link_shared "$release"
  run "$COMPOSER" install --working-dir="$release" --no-dev --prefer-dist --optimize-autoloader --no-interaction --no-progress
  run "$COMPOSER" check-platform-reqs --working-dir="$release" --no-dev

  artisan "$release" config:cache
  artisan "$release" route:cache
  artisan "$release" event:cache
  artisan "$release" view:cache
  artisan "$release" aforo:deploy-check

  log "migration status (staging database):"
  artisan "$release" migrate:status
  local pending=0
  if [ "$DRY_RUN" = 0 ]; then
    pending="$("$PHP" "$release/artisan" migrate:status --pending --no-interaction 2>/dev/null | grep -c 'Pending' || true)"
  fi
  if [ "$pending" != 0 ]; then
    [ "$migrate" = 1 ] || die "$pending pending migration(s): review them and re-run with --migrate"
    mkdir -p "$AFORO_ROOT/shared/deploy-logs"
    "$PHP" "$release/artisan" migrate --pretend --force --no-interaction > "$AFORO_ROOT/shared/deploy-logs/$id-migrate-pretend.sql.txt"
    log "SQL preview saved to shared/deploy-logs/$id-migrate-pretend.sql.txt"
    artisan "$release" migrate --force
  fi
  if [ "$provision" = 1 ]; then
    artisan "$release" aforo:provision
  fi

  artisan "$release" storage:link --force
  switch_to "$release"
  restart_processes
  health "$release"

  # Keep the newest $KEEP_RELEASES releases (current is always among them).
  if [ "$DRY_RUN" = 0 ]; then
    ls -1d "$AFORO_ROOT"/releases/*/ | sort | head -n -"$KEEP_RELEASES" | while read -r old; do
      [ "$(readlink -f "$old")" = "$(readlink -f "$AFORO_ROOT/current")" ] || rm -rf "$old"
    done
  fi
  log "deployed $commit as releases/$id"
}

rollback() {
  for arg in "$@"; do
    case "$arg" in
      --dry-run) DRY_RUN=1 ;;
      *) die "unknown option $arg" ;;
    esac
  done
  preflight
  local current previous
  [ -L "$AFORO_ROOT/current" ] || die "no current release"
  current="$(readlink -f "$AFORO_ROOT/current")"
  previous="$(ls -1d "$AFORO_ROOT"/releases/*/ | sed 's#/$##' | sort | grep -B1 -x "$current" | head -n 1)"
  [ -n "$previous" ] && [ "$previous" != "$current" ] || die "no previous release to roll back to"
  log "rolling back $(basename "$current") -> $(basename "$previous") (code only; schema untouched)"
  # Rebuild caches from the CURRENT shared/.env.
  artisan "$previous" config:cache
  artisan "$previous" route:cache
  artisan "$previous" aforo:deploy-check
  switch_to "$previous"
  restart_processes
  health "$previous"
}

status() {
  log "root: $AFORO_ROOT"
  log "current: $(basename "$(readlink -f "$AFORO_ROOT/current" 2>/dev/null || echo none)") revision $(cat "$AFORO_ROOT/current/REVISION" 2>/dev/null || echo '?')"
  ls -1 "$AFORO_ROOT/releases" 2>/dev/null | sed 's/^/  release /'
}

cmd="${1:-}"; shift || true
case "$cmd" in
  deploy) [ $# -ge 1 ] || die "usage: deploy.sh deploy <git-ref> [--migrate] [--provision] [--dry-run]"; deploy "$@" ;;
  rollback) rollback "$@" ;;
  status) status ;;
  *) die "usage: deploy.sh {deploy <git-ref> [--migrate] [--provision] [--dry-run]|rollback [--dry-run]|status}" ;;
esac
