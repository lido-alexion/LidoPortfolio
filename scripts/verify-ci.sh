#!/usr/bin/env bash
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
mode="${1:---all}"

if [[ "$mode" != "--all" && "$mode" != "--backend" && "$mode" != "--frontend" && "$mode" != "--journeys" ]]; then
  echo "Usage: $0 [--all|--backend|--frontend|--journeys]" >&2
  exit 64
fi

require_command() {
  command -v "$1" >/dev/null 2>&1 || { echo "Missing required command: $1" >&2; exit 1; }
}

verify_php_platform() {
  require_command php
  require_command composer
  local extensions=(curl dom fileinfo mbstring opentelemetry pdo_mysql pdo_sqlite tokenizer xml zip)
  local missing=()
  for extension in "${extensions[@]}"; do
    php -m | tr '[:upper:]' '[:lower:]' | grep -qx "$extension" || missing+=("$extension")
  done
  if ((${#missing[@]})); then
    echo "Missing PHP extension(s) required by CI: ${missing[*]}" >&2
    echo "Install them or use the same PHP setup as .github/workflows/ci.yml." >&2
    exit 1
  fi
}

verify_playwright() {
  # Recent Playwright releases run headless Chromium through a separate shell.
  # Install both artifacts, then prove the executable selected by the installed
  # package exists before starting journeys.
  npx playwright install --with-deps chromium chromium-headless-shell
  node - <<'NODE'
const fs = require('fs');
const { chromium } = require('@playwright/test');
const executable = chromium.executablePath();
if (!fs.existsSync(executable)) {
  console.error(`Playwright Chromium executable is missing: ${executable}`);
  process.exit(1);
}
console.log(`Playwright Chromium executable verified: ${executable}`);
NODE
}

verify_backend() {
  verify_php_platform
  require_command python3
  php "$root/scripts/verify-migration-portability.php"
  (
    cd "$root/app"
    composer check-platform-reqs --no-dev
    composer install --no-interaction --prefer-dist --no-progress
    cp .env.example .env
    php artisan key:generate --force
    php artisan config:clear
    python3 -m unittest discover -s scripts/tests -p 'test_*.py'
    : "${DB_CONNECTION:=mysql}"
    : "${DB_HOST:=127.0.0.1}"
    : "${DB_PORT:=3306}"
    : "${DB_DATABASE:=lidoportfolio_ci}"
    : "${DB_USERNAME:=root}"
    : "${DB_PASSWORD:=root}"
    export DB_CONNECTION DB_HOST DB_PORT DB_DATABASE DB_USERNAME DB_PASSWORD
    [[ "$DB_CONNECTION" == "mysql" ]] || { echo "CI parity requires DB_CONNECTION=mysql." >&2; exit 1; }
    php artisan migrate:fresh --seed --force
    php -d memory_limit=512M vendor/bin/phpunit
    php artisan openapi:v1 --check
  )
}

verify_frontend() {
  require_command node
  require_command npm
  local node_major
  node_major="$(node -p 'process.versions.node.split(".")[0]')"
  [[ "$node_major" -ge 20 ]] || { echo "Node.js 20 or newer is required; found $(node --version)." >&2; exit 1; }
  (
    cd "$root/app"
    npm ci --registry=https://registry.npmjs.org
    npm run test:js
    npm run typecheck
    # Keep the hosted-path build contract identical to the existing CI job.
    VITE_APP_BASE=/portfolio/build/ npm run build
    verify_playwright
    npm run test:e2e:journeys
  )
}

case "$mode" in
  --backend) verify_backend ;;
  --frontend) verify_frontend ;;
  --journeys)
    require_command node
    require_command npm
    node_major="$(node -p 'process.versions.node.split(".")[0]')"
    [[ "$node_major" -ge 20 ]] || { echo "Node.js 20 or newer is required; found $(node --version)." >&2; exit 1; }
    (cd "$root/app" && npm ci --registry=https://registry.npmjs.org && verify_playwright && npm run test:e2e:journeys)
    ;;
  --all) verify_backend; verify_frontend ;;
esac
