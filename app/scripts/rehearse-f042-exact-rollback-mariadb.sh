#!/usr/bin/env bash
set -euo pipefail

# Offline disposable MariaDB rehearsal. Imports only the supplied fixture, then
# runs the application rollback/API suite against a separate ephemeral database.
fixture_dir=${1:-/home/nitty/stox-383-bnagrochem-preflight-20261126-9VC0jl}
expected_sha=acbea5ec602bf11b9c2d539bab0f17930453054949ece791b6fd6e7cd8834a4b
dump="$fixture_dir/portfolio_stock_prices.sql"
[[ -r "$dump" ]] || { echo "Missing approved fixture dump: $dump" >&2; exit 2; }
actual_sha=$(sha256sum "$dump" | awk '{print $1}')
[[ "$actual_sha" == "$expected_sha" ]] || { echo "Fixture SHA mismatch: $actual_sha" >&2; exit 2; }
command -v mariadbd >/dev/null
command -v mariadb-install-db >/dev/null
command -v mariadb >/dev/null

scratch=$(mktemp -d /tmp/f042-rollback-mariadb.XXXXXX)
cleanup() {
  if [[ -S "$scratch/mysql.sock" ]]; then mariadb --no-defaults --protocol=socket --socket="$scratch/mysql.sock" -uroot -e 'SHUTDOWN' >/dev/null 2>&1 || true; fi
  if [[ -n "${server_pid:-}" ]]; then wait "$server_pid" 2>/dev/null || true; fi
  rm -rf "$scratch"
}
trap cleanup EXIT
mkdir "$scratch/data"
mariadb-install-db --no-defaults --datadir="$scratch/data" --auth-root-authentication-method=normal --skip-test-db >"$scratch/init.log" 2>&1
mariadbd --no-defaults --user="$(id -un)" --datadir="$scratch/data" --socket="$scratch/mysql.sock" --pid-file="$scratch/mysql.pid" --skip-networking --wsrep-on=OFF --innodb-buffer-pool-size=32M --max-connections=5 --log-error="$scratch/server.log" &
server_pid=$!
for _ in $(seq 1 100); do
  [[ -S "$scratch/mysql.sock" ]] && mariadb --no-defaults --protocol=socket --socket="$scratch/mysql.sock" -uroot -e 'SELECT 1' >/dev/null 2>&1 && break
  sleep 0.1
done
[[ -S "$scratch/mysql.sock" ]] || { cat "$scratch/server.log" >&2; echo 'Private MariaDB socket did not become ready.' >&2; exit 3; }
server_identity=$(mariadb --no-defaults --batch --skip-column-names --protocol=socket --socket="$scratch/mysql.sock" -uroot -e 'SELECT VERSION(), @@version_comment')
[[ "$server_identity" == *MariaDB* ]] || { echo "Expected MariaDB, got: $server_identity" >&2; exit 3; }

# Import only the supplied price fixture into an isolated scratch schema.
mariadb --no-defaults --protocol=socket --socket="$scratch/mysql.sock" -uroot -e 'CREATE DATABASE rehearsal; USE rehearsal; CREATE TABLE portfolio_stock_prices (id BIGINT PRIMARY KEY, stock_id BIGINT NOT NULL, price_date DATE NOT NULL, open_price DECIMAL(18,4), high_price DECIMAL(18,4), low_price DECIMAL(18,4), close_price DECIMAL(18,4), adjusted_close_price DECIMAL(18,4), volume BIGINT, provider_source VARCHAR(255), data_source VARCHAR(255), created_at TIMESTAMP NULL, KEY (stock_id, price_date));'
mariadb --no-defaults --protocol=socket --socket="$scratch/mysql.sock" -uroot rehearsal < "$dump"
result=$(mariadb --no-defaults --batch --skip-column-names --protocol=socket --socket="$scratch/mysql.sock" -uroot rehearsal -e "SELECT COUNT(*), SUM(price_date < '2026-11-16'), SUM(price_date >= '2026-11-16'), MIN(price_date), MAX(price_date) FROM portfolio_stock_prices WHERE stock_id=7096")
[[ "$result" == $'149\t142\t7\t2026-04-20\t2026-11-25' ]] || { echo "Unexpected fixture shape: $result" >&2; exit 4; }
printf 'fixture_sha256=%s\nrows/pre/post/min/max=%s\n' "$actual_sha" "$result"
printf 'server=%s\n' "$server_identity"
echo 'PASS: private socket, skip-networking, disposable MariaDB, approved price fixture only.'

# Laravel receives a fully explicit test-only connection. No app .env or shared
# DB config is loaded for credentials; the database exists only in this datadir.
app_root=$(cd "$(dirname "$0")/.." && pwd)
mariadb --no-defaults --protocol=socket --socket="$scratch/mysql.sock" -uroot -e "CREATE DATABASE f042_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; CREATE USER 'f042_test'@'localhost' IDENTIFIED BY 'f042-test-only'; GRANT ALL PRIVILEGES ON f042_test.* TO 'f042_test'@'localhost';"
(
  cd "$app_root"
  APP_ENV=testing APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= \
  DB_CONNECTION=mysql DB_HOST=localhost DB_PORT=3306 DB_SOCKET="$scratch/mysql.sock" \
  DB_DATABASE=f042_test DB_USERNAME=f042_test DB_PASSWORD=f042-test-only DB_URL= \
  CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync MAIL_MAILER=array \
  OTEL_SDK_DISABLED=true OTEL_TRACES_EXPORTER=none OTEL_METRICS_EXPORTER=none OTEL_LOGS_EXPORTER=none \
  php artisan test --compact tests/Unit/BnagrochemF042EvidenceValidatorTest.php tests/Unit/DataQualityResolutionServiceTest.php tests/Feature/DataQualityDetectionTest.php tests/Feature/DataQualityApiTest.php tests/Feature/CorporateActionRepairSnapshotConcurrencyTest.php
)
echo 'PASS: rollback/API and deterministic separate-worker race tests completed on ephemeral socket-only MariaDB.'
