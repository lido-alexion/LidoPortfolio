#!/usr/bin/env bash
set -euo pipefail

# Keep the full selection and existing memory limit. Hosted CI uses bounded,
# sequential processes with per-process diagnostics.
if [[ "${GITHUB_ACTIONS:-}" != true ]]; then
  exec php -d memory_limit=512M vendor/bin/phpunit
fi

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
exec python3 "$script_dir/run-ci-phpunit-partitioned.py"
