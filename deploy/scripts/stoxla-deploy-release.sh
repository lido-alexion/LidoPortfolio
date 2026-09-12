#!/usr/bin/env bash
set -euo pipefail

APP_ROOT="${STOXLA_APP_ROOT:-/var/www/stoxla}"
RELEASE_ID="${STOXLA_RELEASE_ID:-$(date -u +%Y%m%d%H%M%S)}"
KEEP_RELEASES="${STOXLA_KEEP_RELEASES:-5}"
PHP_BIN="${STOXLA_PHP_BIN:-/usr/bin/php}"
HEALTH_URL="${STOXLA_HEALTH_URL:-https://stoxla.in/}"

ARCHIVE="${1:-}"

log() {
  printf '[stoxla-deploy] %s\n' "$*"
}

fail() {
  printf '[stoxla-deploy] ERROR: %s\n' "$*" >&2
  exit 1
}

if [[ -z "$ARCHIVE" || ! -f "$ARCHIVE" ]]; then
  fail "release archive path is required"
fi

if [[ "$APP_ROOT" != /var/www/stoxla ]]; then
  fail "refusing unexpected app root: $APP_ROOT"
fi

command -v tar >/dev/null || fail "tar is not installed"
command -v curl >/dev/null || fail "curl is not installed"
[[ -x "$PHP_BIN" ]] || fail "PHP binary not found at $PHP_BIN"

LOCK_DIR="$APP_ROOT/.deploy-lock"
if ! mkdir "$LOCK_DIR" 2>/dev/null; then
  fail "another deployment appears to be running; lock exists at $LOCK_DIR"
fi
trap 'rmdir "$LOCK_DIR" 2>/dev/null || true' EXIT

RELEASES_DIR="$APP_ROOT/releases"
SHARED_DIR="$APP_ROOT/shared"
RELEASE_DIR="$RELEASES_DIR/$RELEASE_ID"

mkdir -p "$RELEASES_DIR" "$SHARED_DIR"
[[ ! -e "$RELEASE_DIR" ]] || fail "release already exists: $RELEASE_DIR"
mkdir -p "$RELEASE_DIR"

log "staging release $RELEASE_ID"
tar -xzf "$ARCHIVE" -C "$RELEASE_DIR"

if [[ ! -f "$RELEASE_DIR/artisan" ]]; then
  fail "archive does not look like a Laravel app root; missing artisan"
fi

if [[ ! -e "$SHARED_DIR/.env" ]]; then
  if [[ -f "$APP_ROOT/.env" && ! -L "$APP_ROOT/.env" ]]; then
    log "copying existing production .env into shared storage"
    cp -p "$APP_ROOT/.env" "$SHARED_DIR/.env"
  else
    fail "missing shared .env; create $SHARED_DIR/.env from the current production environment before deploying"
  fi
fi

if [[ ! -e "$SHARED_DIR/storage" ]]; then
  if [[ -d "$APP_ROOT/storage" && ! -L "$APP_ROOT/storage" ]]; then
    log "copying existing Laravel storage into shared storage"
    cp -a "$APP_ROOT/storage" "$SHARED_DIR/storage"
  else
    mkdir -p "$SHARED_DIR/storage"
  fi
fi

mkdir -p \
  "$SHARED_DIR/storage/app/public" \
  "$SHARED_DIR/storage/framework/cache/data" \
  "$SHARED_DIR/storage/framework/sessions" \
  "$SHARED_DIR/storage/framework/testing" \
  "$SHARED_DIR/storage/framework/views" \
  "$SHARED_DIR/storage/logs"

rm -rf "$RELEASE_DIR/.env" "$RELEASE_DIR/storage"
ln -s ../../shared/.env "$RELEASE_DIR/.env"
ln -s ../../shared/storage "$RELEASE_DIR/storage"
mkdir -p "$RELEASE_DIR/bootstrap/cache"
chmod -R ug+rwX "$SHARED_DIR/storage" "$RELEASE_DIR/bootstrap/cache"

log "running database migrations and Laravel optimization in staged release"
(
  cd "$RELEASE_DIR"
  "$PHP_BIN" artisan optimize:clear --no-interaction
  "$PHP_BIN" artisan migrate --force --no-interaction
  "$PHP_BIN" artisan config:cache --no-interaction
  "$PHP_BIN" artisan route:cache --no-interaction
  "$PHP_BIN" artisan view:cache --no-interaction
  "$PHP_BIN" artisan event:cache --no-interaction
)

if [[ -e "$APP_ROOT/current" && ! -L "$APP_ROOT/current" ]]; then
  fail "$APP_ROOT/current exists but is not a symlink"
fi

if [[ -L "$APP_ROOT/current" ]]; then
  shopt -s dotglob nullglob
  for item in "$RELEASE_DIR"/*; do
    name="$(basename "$item")"
    case "$name" in
      .env|storage)
        ;;
      *)
        if [[ -e "$APP_ROOT/$name" && ! -L "$APP_ROOT/$name" ]]; then
          fail "$APP_ROOT/$name exists and is not a symlink"
        fi
        ;;
    esac
  done
  shopt -u dotglob nullglob
fi

if [[ ! -L "$APP_ROOT/current" ]]; then
  BACKUP_DIR="$APP_ROOT/.pre-cicd-root-$(date -u +%Y%m%d%H%M%S)"
  mkdir -p "$BACKUP_DIR"
  log "moving existing direct-root deployment aside to $BACKUP_DIR"
  shopt -s dotglob nullglob
  for item in "$APP_ROOT"/*; do
    name="$(basename "$item")"
    case "$name" in
      .|..|.deploy-lock|.pre-cicd-root-*|current|releases|shared)
        ;;
      *)
        mv "$item" "$BACKUP_DIR/"
        ;;
    esac
  done
  shopt -u dotglob nullglob
fi

ln -sfn "releases/$RELEASE_ID" "$APP_ROOT/current.new"
mv -Tf "$APP_ROOT/current.new" "$APP_ROOT/current"

log "refreshing top-level symlinks"
shopt -s dotglob nullglob
for item in "$RELEASE_DIR"/*; do
  name="$(basename "$item")"
  case "$name" in
    .env|storage)
      ;;
    *)
      ln -sfn "current/$name" "$APP_ROOT/$name"
      ;;
  esac
done
shopt -u dotglob nullglob
ln -sfn "shared/.env" "$APP_ROOT/.env"
ln -sfn "shared/storage" "$APP_ROOT/storage"

log "warming active release and signaling queue restart"
(
  cd "$APP_ROOT/current"
  "$PHP_BIN" artisan queue:restart --no-interaction
)

log "checking production URL"
curl --fail --silent --show-error --location --max-time 20 "$HEALTH_URL" >/dev/null

log "pruning old releases; keeping $KEEP_RELEASES"
current_target="$(readlink "$APP_ROOT/current")"
find "$RELEASES_DIR" -mindepth 1 -maxdepth 1 -type d -printf '%f\n' \
  | sort -r \
  | awk -v keep="$KEEP_RELEASES" -v current="${current_target#releases/}" 'NR > keep && $0 != current { print }' \
  | while read -r old_release; do
      rm -rf "$RELEASES_DIR/$old_release"
    done

log "release $RELEASE_ID is live"
