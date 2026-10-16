#!/usr/bin/env bash
set -euo pipefail

APP_ROOT="${STOXLA_APP_ROOT:-/var/www/stoxla}"
SYSTEMCTL_BIN="${STOXLA_SYSTEMCTL_BIN:-/usr/bin/systemctl}"
RUNUSER_BIN="${STOXLA_RUNUSER_BIN:-/usr/sbin/runuser}"
WORKER_USER="www-data"
WORKER_GROUP="www-data"
SERVICE="stoxla-ml-acceptance.service"
SOURCE_ROOT="$APP_ROOT/shared/storage/app/private/ml-acceptance/sources"
ACCEPTANCE_ROOT="$APP_ROOT/shared/storage/app/private/ml-acceptance"
CAMPAIGN_ROOT="$APP_ROOT/shared/storage/app/private/ml-acceptance/campaigns"
CACHE_PARENT="$APP_ROOT/shared/storage/framework/cache"
CACHE_ROOT="$CACHE_PARENT/ml-datasets"
MODEL_ROOT="$APP_ROOT/shared/ml/models"
LOG_ROOT="$APP_ROOT/shared/storage/logs"
FRAMEWORK_CACHE="$APP_ROOT/current/bootstrap/cache"
PYTHON="$APP_ROOT/shared/python/ml/bin/python"
ADAPTER="$APP_ROOT/current/scripts/ml_adapter.py"

fail() {
  printf '[stoxla-ml-acceptance-preflight] ERROR: %s\n' "$*" >&2
  exit 1
}

[[ "$APP_ROOT" == /var/www/stoxla ]] || fail "refusing unexpected app root"
[[ "$(id -u)" == 0 ]] || fail "run this read-only preflight with sudo"
[[ -x "$RUNUSER_BIN" ]] || fail "runuser is unavailable"
[[ -x "$SYSTEMCTL_BIN" ]] || fail "systemctl is unavailable"

effective_user="$("$SYSTEMCTL_BIN" show "$SERVICE" --property=User --value)"
effective_group="$("$SYSTEMCTL_BIN" show "$SERVICE" --property=Group --value)"
[[ "$effective_user" == "$WORKER_USER" && "$effective_group" == "$WORKER_GROUP" ]] \
  || fail "systemd has not loaded the reviewed www-data:www-data identity override"
service_state="$("$SYSTEMCTL_BIN" is-active "$SERVICE" || true)"
[[ "$service_state" == inactive || "$service_state" == failed ]] \
  || fail "worker must remain stopped during access preflight (state: $service_state)"

as_worker() {
  # The invoking admin may run this from a private home directory. GNU find
  # (and other worker tools) cannot restore an inherited cwd that www-data
  # cannot traverse, so establish a neutral cwd before executing any checks.
  "$RUNUSER_BIN" -u "$WORKER_USER" -- /bin/sh -c 'cd / && exec "$@"' stoxla-ml-acceptance-worker "$@"
}

as_worker test -r "$APP_ROOT/current/artisan" && as_worker test -r "$ADAPTER" && as_worker test -x "$PYTHON" \
  || fail "deployed artisan, adapter, or configured Python executable is inaccessible"
as_worker "$PYTHON" -c 'import sklearn' >/dev/null 2>&1 \
  || fail "ML Python environment is not importable as www-data"

for directory in "$SOURCE_ROOT" "$ACCEPTANCE_ROOT" "$CACHE_PARENT" "$MODEL_ROOT" "$LOG_ROOT" "$FRAMEWORK_CACHE"; do
  [[ -d "$directory" ]] || fail "required ML runtime directory is missing"
  as_worker test -r "$directory" && as_worker test -x "$directory" \
    || fail "ML runtime directory is not accessible as www-data"
done

# Preserve the owner-only source contract on each generated source directory.
# The shared parent can be setgid/group-accessible; a source itself cannot.
# This observes permissions only and never changes ownership or mode.
source_dir_violations="$(as_worker find "$SOURCE_ROOT" -mindepth 1 -type d \
  \( ! -user "$WORKER_USER" -o ! -perm -0700 -o -perm /0077 \) -printf '.' | wc -c)"
[[ "$source_dir_violations" -eq 0 ]] \
  || fail "$source_dir_violations private source directories do not meet the owner-only www-data contract"
source_file_violations="$(as_worker find "$SOURCE_ROOT" -type f \
  \( ! -user "$WORKER_USER" -o ! -readable -o -perm /0077 \) -printf '.' | wc -c)"
[[ "$source_file_violations" -eq 0 ]] \
  || fail "$source_file_violations private source files do not meet the owner-only www-data contract"

as_worker test -w "$CACHE_PARENT" && as_worker test -w "$MODEL_ROOT" \
  || fail "ML dataset cache or model artifact directory is not writable as www-data"
as_worker test -w "$ACCEPTANCE_ROOT" && as_worker test -w "$LOG_ROOT" && as_worker test -w "$FRAMEWORK_CACHE" \
  || fail "campaign evidence, application logs, or Laravel cache are not writable as www-data"
if as_worker test -d "$CAMPAIGN_ROOT"; then
  as_worker test -w "$CAMPAIGN_ROOT" \
    || fail "private campaign evidence is not writable as www-data"
  evidence_dir_violations="$(as_worker find "$CAMPAIGN_ROOT" -mindepth 1 -type d \
    \( ! -user "$WORKER_USER" -o ! -perm -0700 -o -perm /0077 \) -printf '.' | wc -c)"
  [[ "$evidence_dir_violations" -eq 0 ]] \
    || fail "$evidence_dir_violations private campaign directories do not meet the owner-only www-data contract"
  evidence_file_violations="$(as_worker find "$CAMPAIGN_ROOT" -type f \
    \( ! -user "$WORKER_USER" -o ! -readable -o -perm /0077 \) -printf '.' | wc -c)"
  [[ "$evidence_file_violations" -eq 0 ]] \
    || fail "$evidence_file_violations private campaign files do not meet the owner-only www-data contract"
fi
if as_worker test -d "$CACHE_ROOT"; then
  as_worker test -w "$CACHE_ROOT" || fail "an existing ML dataset cache is not writable as www-data"
fi

printf '[stoxla-ml-acceptance-preflight] PASS: stopped worker identity, sealed-source access, Python imports, cache and model paths verified\n'
