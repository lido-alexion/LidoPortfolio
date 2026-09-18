#!/usr/bin/env bash
set -euo pipefail

APP_ROOT="${STOXLA_APP_ROOT:-/var/www/stoxla}"
PHP_BIN="${STOXLA_PHP_BIN:-/usr/bin/php}"
HEALTH_URL="${STOXLA_HEALTH_URL:-https://stoxla.in/}"
TARGET_RELEASE="${1:-}"
PHP_FPM_SERVICE="${STOXLA_PHP_FPM_SERVICE:-php8.4-fpm}"
QUEUE_SERVICE="${STOXLA_QUEUE_SERVICE:-stoxla-queue}"
SYSTEMCTL_BIN="${STOXLA_SYSTEMCTL_BIN:-/usr/bin/systemctl}"
SUDO_BIN="${STOXLA_SUDO_BIN:-/usr/bin/sudo}"
REQUIRED_QUEUES="${STOXLA_REQUIRED_QUEUES:-notifications,default}"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
RUNTIME_HEALTH_CHECK="${STOXLA_RUNTIME_HEALTH_CHECK:-$SCRIPT_DIR/stoxla-runtime-health-check.sh}"

log() {
  printf '[stoxla-rollback] %s\n' "$*"
}

fail() {
  printf '[stoxla-rollback] ERROR: %s\n' "$*" >&2
  exit 1
}

if [[ "$APP_ROOT" != /var/www/stoxla ]]; then
  fail "refusing unexpected app root: $APP_ROOT"
fi

RELEASES_DIR="$APP_ROOT/releases"
[[ -d "$RELEASES_DIR" ]] || fail "release directory does not exist: $RELEASES_DIR"
[[ -L "$APP_ROOT/current" ]] || fail "$APP_ROOT/current is not a symlink"
[[ -x "$RUNTIME_HEALTH_CHECK" ]] || fail "runtime health check is not executable: $RUNTIME_HEALTH_CHECK"

log "checking required runtime-service privileges"
"$SUDO_BIN" -n "$SYSTEMCTL_BIN" show "$PHP_FPM_SERVICE" --property=Id >/dev/null \
  || fail "rollback user needs passwordless permission to inspect/reload $PHP_FPM_SERVICE"
"$SUDO_BIN" -n "$SYSTEMCTL_BIN" show "$QUEUE_SERVICE" --property=Id >/dev/null \
  || fail "rollback user needs passwordless permission to inspect/restart $QUEUE_SERVICE"

if [[ -z "$TARGET_RELEASE" ]]; then
  current="$(basename "$(readlink "$APP_ROOT/current")")"
  TARGET_RELEASE="$(
    find "$RELEASES_DIR" -mindepth 1 -maxdepth 1 -type d -printf '%f\n' \
      | sort -r \
      | awk -v current="$current" '$0 != current { print; exit }'
  )"
fi

[[ -n "$TARGET_RELEASE" ]] || fail "no previous release found"
[[ -d "$RELEASES_DIR/$TARGET_RELEASE" ]] || fail "target release not found: $TARGET_RELEASE"

TARGET_COMMIT="$("$PHP_BIN" -r '
  $data = json_decode(file_get_contents($argv[1]), true);
  echo is_array($data) ? ($data["commit_sha"] ?? "") : "";
' "$RELEASES_DIR/$TARGET_RELEASE/bootstrap/build-info.json")"
[[ -n "$TARGET_COMMIT" ]] || fail "rollback release build metadata is missing commit_sha"

log "switching current symlink to $TARGET_RELEASE"
ln -sfn "releases/$TARGET_RELEASE" "$APP_ROOT/current.new"
mv -Tf "$APP_ROOT/current.new" "$APP_ROOT/current"

(
  cd "$APP_ROOT/current"
  "$PHP_BIN" artisan config:cache --no-interaction
  "$PHP_BIN" artisan route:cache --no-interaction
  "$PHP_BIN" artisan view:cache --no-interaction
  "$PHP_BIN" artisan event:cache --no-interaction
  "$PHP_BIN" artisan queue:restart --no-interaction
)

log "gracefully reloading PHP-FPM after rollback"
"$SUDO_BIN" -n "$SYSTEMCTL_BIN" reload "$PHP_FPM_SERVICE" \
  || fail "could not reload $PHP_FPM_SERVICE after rollback"

log "restarting the managed queue worker after rollback"
"$SUDO_BIN" -n "$SYSTEMCTL_BIN" restart "$QUEUE_SERVICE" \
  || fail "could not restart $QUEUE_SERVICE after rollback"

log "running hard public release-identity and runtime health gate"
STOXLA_APP_ROOT="$APP_ROOT" \
STOXLA_PHP_BIN="$PHP_BIN" \
STOXLA_HEALTH_URL="$HEALTH_URL" \
STOXLA_EXPECTED_COMMIT="$TARGET_COMMIT" \
STOXLA_QUEUE_SERVICE="$QUEUE_SERVICE" \
STOXLA_REQUIRED_QUEUES="$REQUIRED_QUEUES" \
"$RUNTIME_HEALTH_CHECK"

log "release $TARGET_RELEASE is live"
