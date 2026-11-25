#!/usr/bin/env bash
set -euo pipefail

# Keep the full selection and existing memory limit. Hosted CI adds diagnostics.
if [[ "${GITHUB_ACTIONS:-}" != true ]]; then
  exec php -d memory_limit=512M vendor/bin/phpunit
fi

events="$(mktemp "${RUNNER_TEMP:-/tmp}/stox-phpunit-events.XXXXXX")"
trap 'rm -f "$events"' EXIT
if /usr/bin/time -v php -d memory_limit=512M vendor/bin/phpunit --log-events-text "$events"; then
  exit 0
else
  status=$?
  echo "PHPUnit exited with status $status; last recorded events:" >&2
  tail -n 100 "$events" >&2 || true
  exit "$status"
fi
