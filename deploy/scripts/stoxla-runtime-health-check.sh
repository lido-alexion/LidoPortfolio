#!/usr/bin/env bash
set -euo pipefail

APP_ROOT="${STOXLA_APP_ROOT:-/var/www/stoxla}"
PHP_BIN="${STOXLA_PHP_BIN:-/usr/bin/php}"
SYSTEMCTL_BIN="${STOXLA_SYSTEMCTL_BIN:-/usr/bin/systemctl}"
HEALTH_URL="${STOXLA_HEALTH_URL:-https://stoxla.in/}"
EXPECTED_COMMIT="${STOXLA_EXPECTED_COMMIT:-}"
QUEUE_SERVICE="${STOXLA_QUEUE_SERVICE:-stoxla-queue}"
REQUIRED_QUEUES="${STOXLA_REQUIRED_QUEUES:-notifications,default}"
SCHEDULER_MAX_AGE_SECONDS="${STOXLA_SCHEDULER_MAX_AGE_SECONDS:-300}"
PHP_FPM_GROUP="${STOXLA_PHP_FPM_GROUP:-www-data}"
FUNDAMENTALS_PYTHON="${STOXLA_FUNDAMENTALS_PYTHON:-$APP_ROOT/shared/python/fundamentals/bin/python}"
FUNDAMENTALS_ADAPTER="${STOXLA_FUNDAMENTALS_ADAPTER:-$APP_ROOT/current/scripts/yahoo_fundamentals.py}"
FUNDAMENTALS_YFINANCE_VERSION="${STOXLA_FUNDAMENTALS_YFINANCE_VERSION:-1.7.0}"

log() {
  printf '[stoxla-runtime-health] %s\n' "$*"
}

fail() {
  printf '[stoxla-runtime-health] ERROR: %s\n' "$*" >&2
  exit 1
}

[[ "$APP_ROOT" == /var/www/stoxla ]] || fail "refusing unexpected app root: $APP_ROOT"
[[ -x "$PHP_BIN" ]] || fail "PHP binary not found at $PHP_BIN"
[[ -L "$APP_ROOT/current" ]] || fail "current release symlink is missing"
[[ -f "$APP_ROOT/current/bootstrap/build-info.json" ]] || fail "active release build metadata is missing"
[[ -x "$FUNDAMENTALS_PYTHON" ]] || fail "fundamentals Python runtime is not executable: $FUNDAMENTALS_PYTHON"
[[ -f "$FUNDAMENTALS_ADAPTER" ]] || fail "fundamentals Python adapter is missing: $FUNDAMENTALS_ADAPTER"
installed_yfinance="$("$FUNDAMENTALS_PYTHON" -c 'import yfinance; print(yfinance.__version__)' 2>/dev/null)" \
  || fail "fundamentals Python runtime cannot import yfinance"
[[ "$installed_yfinance" == "$FUNDAMENTALS_YFINANCE_VERSION" ]] \
  || fail "fundamentals yfinance version $installed_yfinance does not match required $FUNDAMENTALS_YFINANCE_VERSION"

debug_state="$(cd "$APP_ROOT/current" && "$PHP_BIN" artisan tinker --execute='echo config("app.env")."|".(config("portfolio.debug_agent.enabled") ? "true" : "false");' --no-interaction)"
[[ "$debug_state" == "production|false" ]] || fail "production DebugAgent is enabled or app environment is not production"

grep -Eq '^LIDO_AGENT_DEBUG_ENABLED=false([[:space:]]*#.*)?$' "$APP_ROOT/shared/.env" \
  || fail "production shared .env must explicitly disable DebugAgent"

for writable_path in "$APP_ROOT/current/storage/logs" "$APP_ROOT/current/bootstrap/cache"; do
  [[ -d "$writable_path" ]] || fail "required writable path is missing: $writable_path"
  find "$writable_path" -type d \( ! -group "$PHP_FPM_GROUP" -o ! -perm -g+w -o ! -perm -2000 \) -print -quit | grep -q . \
    && fail "directory ownership/permissions are unsafe under $writable_path"
  find "$writable_path" -type f \( ! -group "$PHP_FPM_GROUP" -o ! -perm -g+w \) -print -quit | grep -q . \
    && fail "file ownership/permissions are unsafe under $writable_path"
done

release_commit="$("$PHP_BIN" -r '
  $data = json_decode(file_get_contents($argv[1]), true);
  echo is_array($data) ? ($data["commit_sha"] ?? "") : "";
' "$APP_ROOT/current/bootstrap/build-info.json")"
[[ -n "$release_commit" ]] || fail "active release build metadata lacks commit_sha"

if [[ -n "$EXPECTED_COMMIT" && "$release_commit" != "$EXPECTED_COMMIT" ]]; then
  fail "active release build metadata commit $release_commit does not match expected $EXPECTED_COMMIT"
fi

log "checking public build identity"
build_info="$(mktemp)"
cleanup() {
  rm -f "$build_info"
}
trap cleanup EXIT

curl --fail --silent --show-error --location --max-time 20 \
  "${HEALTH_URL%/}/api/build-info" > "$build_info"
public_commit="$("$PHP_BIN" -r '
  $payload = json_decode(file_get_contents($argv[1]), true);
  echo is_array($payload) ? ($payload["data"]["commit_sha"] ?? "") : "";
' "$build_info")"
[[ "$public_commit" == "$release_commit" ]] || fail "public build-info commit $public_commit does not match active release $release_commit"

log "checking root and browser module"
html="$(mktemp)"
headers="$(mktemp)"
cleanup() {
  rm -f "$build_info" "$html" "$headers"
}

curl --fail --silent --show-error --location --max-time 20 "$HEALTH_URL" > "$html"
module_src="$(grep -oE '<script[^>]+type="module"[^>]+src="[^"]+"' "$html" | head -n 1 | sed -E 's/.*src="([^"]+)".*/\1/')"
[[ -n "$module_src" ]] || fail "could not find browser module in root HTML"
curl --fail --silent --show-error --location --max-time 20 -D "$headers" -o /dev/null "${HEALTH_URL%/}$module_src"
grep -qiE '^content-type: .*javascript' "$headers" || fail "browser module $module_src did not return JavaScript"

log "checking queue service and queue coverage"
"$SYSTEMCTL_BIN" is-active --quiet "$QUEUE_SERVICE" || fail "queue service $QUEUE_SERVICE is not active"
worker_command="$("$SYSTEMCTL_BIN" show "$QUEUE_SERVICE" --property=ExecStart --value)"
[[ "$worker_command" == *"--queue=$REQUIRED_QUEUES"* ]] || fail "queue service does not consume required queues $REQUIRED_QUEUES"

log "checking scheduler heartbeat"
heartbeat="$(cd "$APP_ROOT" && "$PHP_BIN" artisan tinker --execute='echo (string) \App\Models\Setting::getValue("schedule_run_heartbeat_at", "");' --no-interaction)"
[[ -n "$heartbeat" ]] || fail "scheduler heartbeat is missing"
"$PHP_BIN" -r '
  $heartbeat = new DateTimeImmutable($argv[1]);
  $age = time() - $heartbeat->getTimestamp();
  if ($age < 0 || $age > (int) $argv[2]) {
      fwrite(STDERR, "scheduler heartbeat age {$age}s is outside the allowed range\n");
      exit(1);
  }
' "$heartbeat" "$SCHEDULER_MAX_AGE_SECONDS" || fail "scheduler heartbeat is stale"

log "runtime health is green for $release_commit"
