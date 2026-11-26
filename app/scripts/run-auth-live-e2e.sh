#!/usr/bin/env bash
set -euo pipefail

app_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
temp_root="$(mktemp -d "${TMPDIR:-/tmp}/stox-auth-e2e.XXXXXX")"
backend_pid=''
cleanup() {
  local status=$?
  if [[ -n "$backend_pid" ]]; then
    # The PHP development server may fork workers. Stop the entire isolated
    # process group so a failed Playwright run cannot leave listeners behind.
    kill -TERM -- "-$backend_pid" 2>/dev/null || true
    wait "$backend_pid" 2>/dev/null || true
  fi
  if [[ $status -ne 0 && -n "${STOX_AUTH_E2E_OUTPUT_DIR:-}" && -f "$temp_root/laravel.log" ]]; then
    cp "$temp_root/laravel.log" "$STOX_AUTH_E2E_OUTPUT_DIR/harness-laravel.log"
  fi
  rm -f "$temp_root/readiness.json"
  rm -rf "$temp_root"
  return "$status"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

cd "$app_root"
cat >"$temp_root/auth-e2e.env" <<'ENV'
APP_ENV=testing
APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=
APP_URL=http://127.0.0.1:4178
DB_CONNECTION=sqlite
SESSION_DRIVER=database
CACHE_STORE=array
ENV
export LIDO_ENV_PATH="$temp_root/auth-e2e.env"
export APP_ENV=testing
export APP_KEY='base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA='
export APP_URL='http://127.0.0.1:4178'
export APP_DEBUG=false
export DB_CONNECTION=sqlite
export DB_DATABASE="$temp_root/auth-e2e.sqlite"
export DB_FOREIGN_KEYS=true
export SESSION_DRIVER=database
export SESSION_COOKIE=stox-auth-e2e-session
export SESSION_LIFETIME=30
export SESSION_SECURE_COOKIE=false
export SESSION_SAME_SITE=lax
export SESSION_DOMAIN=
export SESSION_PATH=/
export CACHE_STORE=array
export QUEUE_CONNECTION=sync
export MAIL_MAILER=array
export SANCTUM_STATEFUL_DOMAINS='127.0.0.1:4178,localhost:4178'
export PHP_CLI_SERVER_WORKERS=4
export STOX_AUTH_E2E_OUTPUT_DIR="$app_root/tests/e2e/test-results/auth-live"
export OTEL_SDK_DISABLED=true
export LIDO_TELEMETRY_OFFICIAL_SDK_ENABLED=false
export LOG_CHANNEL=stderr

# Fail closed before any Artisan/PHP invocation if the disposable boundary was
# changed by ambient variables or a future harness edit.
case "$LIDO_ENV_PATH" in "$temp_root"/*) ;; *) echo "LIDO_ENV_PATH escaped disposable fixture directory." >&2; exit 64 ;; esac
case "$DB_DATABASE" in "$temp_root"/*.sqlite) ;; *) echo "Live auth fixture requires its disposable SQLite database." >&2; exit 64 ;; esac
[[ "$DB_CONNECTION" == sqlite && "$APP_ENV" == testing && "$APP_DEBUG" == false ]] || {
  echo "Unsafe live auth fixture configuration." >&2; exit 64;
}
mkdir -p "$STOX_AUTH_E2E_OUTPUT_DIR"

touch "$DB_DATABASE"
php artisan migrate:fresh --force
php tests/e2e/authFixture.php

setsid env PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8001 -t public public/index.php >"$temp_root/laravel.log" 2>&1 &
backend_pid=$!
# Sanctum's stateful API middleware is selected from Origin/Referer. A bare
# health request to this session-backed endpoint is stateless and would fail
# when AuthController asks for the session. Emulate the browser origin that
# Playwright will use through Vite, and require a real CSRF token response.
backend_ready() {
  curl --silent --show-error --fail \
    --header 'Accept: application/json' \
    --header 'Origin: http://127.0.0.1:4178' \
    --header 'Referer: http://127.0.0.1:4178/' \
    --header 'X-Stox-Auth-Context: investor' \
    --cookie-jar "$temp_root/readiness-cookies.txt" \
    --output "$temp_root/readiness.json" \
    http://127.0.0.1:8001/api/auth/csrf-token \
    2>/dev/null \
    && php -r '
      $r=json_decode(file_get_contents($argv[1]),true);
      $cookies=file_get_contents($argv[2]);
      $hasSessionCookie=preg_match("/^(?:#HttpOnly_)?127\\.0\\.0\\.1\\s+FALSE\\s+\\/\\s+FALSE\\s+\\d+\\s+stox-auth-e2e-session\\s+\\S+$/m", $cookies) === 1;
      exit(is_string($r["token"]??null) && $r["token"]!=="" && $hasSessionCookie ? 0 : 1);
    ' "$temp_root/readiness.json" "$temp_root/readiness-cookies.txt"
}
for attempt in $(seq 1 60); do
  if backend_ready; then break; fi
  if ! kill -0 "$backend_pid" 2>/dev/null; then cat "$temp_root/laravel.log" >&2; exit 1; fi
  sleep 1
done
backend_ready || { cat "$temp_root/laravel.log" >&2; exit 1; }

npx playwright test --config tests/e2e/auth-live.playwright.config.js
