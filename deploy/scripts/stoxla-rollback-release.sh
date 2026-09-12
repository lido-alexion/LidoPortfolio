#!/usr/bin/env bash
set -euo pipefail

APP_ROOT="${STOXLA_APP_ROOT:-/var/www/stoxla}"
PHP_BIN="${STOXLA_PHP_BIN:-/usr/bin/php}"
HEALTH_URL="${STOXLA_HEALTH_URL:-https://stoxla.in/}"
TARGET_RELEASE="${1:-}"

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

curl --fail --silent --show-error --location --max-time 20 "$HEALTH_URL" >/dev/null
log "release $TARGET_RELEASE is live"
