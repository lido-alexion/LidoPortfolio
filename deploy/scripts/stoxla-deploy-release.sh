#!/usr/bin/env bash
set -euo pipefail

APP_ROOT="${STOXLA_APP_ROOT:-/var/www/stoxla}"
RELEASE_ID="${STOXLA_RELEASE_ID:-$(date -u +%Y%m%d%H%M%S)}"
KEEP_RELEASES="${STOXLA_KEEP_RELEASES:-5}"
PHP_BIN="${STOXLA_PHP_BIN:-/usr/bin/php}"
HEALTH_URL="${STOXLA_HEALTH_URL:-https://stoxla.in/}"
EXPECTED_COMMIT="${STOXLA_EXPECTED_COMMIT:-}"
PHP_FPM_SERVICE="${STOXLA_PHP_FPM_SERVICE:-php8.4-fpm}"
QUEUE_SERVICE="${STOXLA_QUEUE_SERVICE:-stoxla-queue}"
SYSTEMCTL_BIN="${STOXLA_SYSTEMCTL_BIN:-/usr/bin/systemctl}"
SUDO_BIN="${STOXLA_SUDO_BIN:-/usr/bin/sudo}"
REQUIRED_QUEUES="${STOXLA_REQUIRED_QUEUES:-notifications,default}"
PHP_FPM_GROUP="${STOXLA_PHP_FPM_GROUP:-www-data}"
FUNDAMENTALS_SYSTEM_PYTHON="${STOXLA_FUNDAMENTALS_SYSTEM_PYTHON:-/usr/bin/python3.12}"
FUNDAMENTALS_SHARED_DIR="${STOXLA_FUNDAMENTALS_SHARED_DIR:-$APP_ROOT/shared/python/fundamentals}"
ML_SHARED_DIR="${STOXLA_ML_SHARED_DIR:-$APP_ROOT/shared/python/ml}"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
RUNTIME_HEALTH_CHECK="${STOXLA_RUNTIME_HEALTH_CHECK:-$SCRIPT_DIR/stoxla-runtime-health-check.sh}"

ARCHIVE="${1:-}"

log() {
  printf '[stoxla-deploy] %s\n' "$*"
}

fail() {
  printf '[stoxla-deploy] ERROR: %s\n' "$*" >&2
  exit 1
}

prepare_writable_tree() {
  local path="$1"
  local deploy_user

  deploy_user="$(id -un)"

  # Existing PHP-owned files are validated by the health check; only normalize
  # files the deployment user can safely change.
  find "$path" -type d -user "$deploy_user" \
    -exec chgrp "$PHP_FPM_GROUP" {} + \
    -exec chmod 2775 {} +
  find "$path" -type f -user "$deploy_user" \
    -exec chgrp "$PHP_FPM_GROUP" {} + \
    -exec chmod 0664 {} +
}

prepare_fundamentals_python() {
  local requirements="$1"
  local venv_python="$FUNDAMENTALS_SHARED_DIR/bin/python"
  local marker="$FUNDAMENTALS_SHARED_DIR/.requirements.sha256"
  local requirements_hash

  [[ -x "$FUNDAMENTALS_SYSTEM_PYTHON" ]] \
    || fail "required fundamentals Python runtime is missing: $FUNDAMENTALS_SYSTEM_PYTHON"
  mkdir -p "$(dirname "$FUNDAMENTALS_SHARED_DIR")"
  if [[ ! -x "$venv_python" ]]; then
    log "creating shared fundamentals Python virtualenv"
    "$FUNDAMENTALS_SYSTEM_PYTHON" -m venv "$FUNDAMENTALS_SHARED_DIR"
  fi
  requirements_hash="$(sha256sum "$requirements" | awk '{print $1}')"
  if [[ ! -f "$marker" || "$(cat "$marker")" != "$requirements_hash" ]]; then
    log "installing pinned fundamentals Python dependencies"
    "$venv_python" -m pip install --disable-pip-version-check --requirement "$requirements"
    printf '%s\n' "$requirements_hash" > "$marker"
  fi
  [[ -x "$venv_python" ]] || fail "fundamentals Python virtualenv is not executable"
}

prepare_ml_python() {
  local requirements="$1"
  local venv_python="$ML_SHARED_DIR/bin/python"
  local marker="$ML_SHARED_DIR/.requirements.sha256"
  local requirements_hash

  [[ -x "$FUNDAMENTALS_SYSTEM_PYTHON" ]] \
    || fail "required ML Python runtime is missing: $FUNDAMENTALS_SYSTEM_PYTHON"
  mkdir -p "$(dirname "$ML_SHARED_DIR")"
  if [[ ! -x "$venv_python" ]]; then
    log "creating shared ML Python virtualenv"
    "$FUNDAMENTALS_SYSTEM_PYTHON" -m venv "$ML_SHARED_DIR"
  fi
  requirements_hash="$(sha256sum "$requirements" | awk '{print $1}')"
  if [[ ! -f "$marker" || "$(cat "$marker")" != "$requirements_hash" ]]; then
    log "installing pinned ML Python dependencies"
    "$venv_python" -m pip install --disable-pip-version-check --requirement "$requirements"
    printf '%s\n' "$requirements_hash" > "$marker"
  fi
  [[ -x "$venv_python" ]] || fail "ML Python virtualenv is not executable"
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
[[ -x "$RUNTIME_HEALTH_CHECK" ]] || fail "runtime health check is not executable: $RUNTIME_HEALTH_CHECK"

log "checking required runtime-service privileges"
"$SUDO_BIN" -n "$SYSTEMCTL_BIN" show "$PHP_FPM_SERVICE" --property=Id >/dev/null \
  || fail "deployment user needs passwordless permission to inspect/reload $PHP_FPM_SERVICE"
"$SUDO_BIN" -n "$SYSTEMCTL_BIN" show "$QUEUE_SERVICE" --property=Id >/dev/null \
  || fail "deployment user needs passwordless permission to inspect/restart $QUEUE_SERVICE"

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
[[ -f "$RELEASE_DIR/deploy/python/fundamentals-requirements.txt" ]] \
  || fail "pinned fundamentals Python requirements are missing from the release"
[[ -f "$RELEASE_DIR/deploy/python/ml-requirements.txt" ]] \
  || fail "pinned ML Python requirements are missing from the release"

RELEASE_COMMIT="$("$PHP_BIN" -r '
  $data = json_decode(file_get_contents($argv[1]), true);
  echo is_array($data) ? ($data["commit_sha"] ?? "") : "";
' "$RELEASE_DIR/bootstrap/build-info.json")"
[[ -n "$RELEASE_COMMIT" ]] || fail "release build metadata is missing commit_sha"
if [[ -n "$EXPECTED_COMMIT" && "$RELEASE_COMMIT" != "$EXPECTED_COMMIT" ]]; then
  fail "release build metadata commit $RELEASE_COMMIT does not match expected $EXPECTED_COMMIT"
fi

if [[ ! -e "$SHARED_DIR/.env" ]]; then
  if [[ -f "$APP_ROOT/.env" && ! -L "$APP_ROOT/.env" ]]; then
    log "copying existing production .env into shared storage"
    cp -p "$APP_ROOT/.env" "$SHARED_DIR/.env"
  else
    fail "missing shared .env; create $SHARED_DIR/.env from the current production environment before deploying"
  fi
fi

grep -Eq '^LIDO_AGENT_DEBUG_ENABLED=false([[:space:]]*#.*)?$' "$SHARED_DIR/.env" \
  || fail "production shared .env must explicitly set LIDO_AGENT_DEBUG_ENABLED=false"

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

mkdir -p "$SHARED_DIR/ml/models"
prepare_writable_tree "$SHARED_DIR/ml"

prepare_fundamentals_python "$RELEASE_DIR/deploy/python/fundamentals-requirements.txt"
prepare_ml_python "$RELEASE_DIR/deploy/python/ml-requirements.txt"

rm -rf "$RELEASE_DIR/.env" "$RELEASE_DIR/storage"
ln -s ../../shared/.env "$RELEASE_DIR/.env"
ln -s ../../shared/storage "$RELEASE_DIR/storage"
mkdir -p "$RELEASE_DIR/bootstrap/cache"
log "normalizing shared Laravel writable paths for $PHP_FPM_GROUP"
prepare_writable_tree "$SHARED_DIR/storage"
prepare_writable_tree "$RELEASE_DIR/bootstrap/cache"

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

debug_state="$(cd "$RELEASE_DIR" && "$PHP_BIN" artisan tinker --execute='echo config("app.env")."|".(config("portfolio.debug_agent.enabled") ? "true" : "false");' --no-interaction)"
[[ "$debug_state" == "production|false" ]] \
  || fail "effective production DebugAgent state is unsafe: enabled or non-production environment"

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

log "gracefully reloading PHP-FPM after the release switch"
"$SUDO_BIN" -n "$SYSTEMCTL_BIN" reload "$PHP_FPM_SERVICE" \
  || fail "could not reload $PHP_FPM_SERVICE after release activation"

log "restarting the managed queue worker after the release switch"
"$SUDO_BIN" -n "$SYSTEMCTL_BIN" restart "$QUEUE_SERVICE" \
  || fail "could not restart $QUEUE_SERVICE after release activation"

log "running hard public release-identity and runtime health gate"
STOXLA_APP_ROOT="$APP_ROOT" \
STOXLA_PHP_BIN="$PHP_BIN" \
STOXLA_HEALTH_URL="$HEALTH_URL" \
STOXLA_EXPECTED_COMMIT="$RELEASE_COMMIT" \
STOXLA_QUEUE_SERVICE="$QUEUE_SERVICE" \
STOXLA_REQUIRED_QUEUES="$REQUIRED_QUEUES" \
STOXLA_FUNDAMENTALS_PYTHON="$FUNDAMENTALS_SHARED_DIR/bin/python" \
STOXLA_FUNDAMENTALS_ADAPTER="$APP_ROOT/current/scripts/yahoo_fundamentals.py" \
STOXLA_ML_PYTHON="$ML_SHARED_DIR/bin/python" \
STOXLA_ML_ADAPTER="$APP_ROOT/current/scripts/ml_adapter.py" \
STOXLA_ML_MODEL_DIRECTORY="$SHARED_DIR/ml/models" \
"$RUNTIME_HEALTH_CHECK"

log "pruning old releases; keeping $KEEP_RELEASES"
current_target="$(readlink "$APP_ROOT/current")"
find "$RELEASES_DIR" -mindepth 1 -maxdepth 1 -type d -printf '%f\n' \
  | sort -r \
  | awk -v keep="$KEEP_RELEASES" -v current="${current_target#releases/}" 'NR > keep && $0 != current { print }' \
  | while read -r old_release; do
      rm -rf "$RELEASES_DIR/$old_release"
    done

log "release $RELEASE_ID is live"
