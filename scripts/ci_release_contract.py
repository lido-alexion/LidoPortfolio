#!/usr/bin/env python3
"""Small offline-testable scope and deployment gate for the canonical CI DAG."""

from __future__ import annotations

import fnmatch
import os
import re
import sys


BACKEND = (
    "app/app/*", "app/bootstrap/*", "app/config/*", "app/database/*",
    "app/routes/*", "app/scripts/**", "app/deploy/**", "app/tests/*.php",
    "app/tests/**/*.php", "app/composer.json", "app/composer.lock",
    "app/phpunit.xml", "app/openapi/**", "app/public/docs/openapi-v1.json",
    "shared/**", "deploy/**", "scripts/**", ".github/workflows/*.yml", ".github/workflows/*.yaml",
)
FRONTEND = (
    "app/resources/*", "app/resources/**/*", "app/tests/js/*",
    "app/tests/js/**/*", "app/tests/e2e/**", "docs/user-journeys/**",
    "app/package.json", "app/package-lock.json", "app/scripts/write-build-info.mjs",
    "app/tsconfig.json", "app/vite.config.js", "app/vitest.config.js",
    "scripts/**", ".github/workflows/*.yml", ".github/workflows/*.yaml",
)
DEPLOYABLE = (
    "app/app/*", "app/bootstrap/*", "app/config/*", "app/database/*",
    "app/routes/*", "app/scripts/**", "app/deploy/**", "app/tests/*.php",
    "app/tests/**/*.php", "app/composer.json", "app/composer.lock",
    "app/phpunit.xml", "app/openapi/**", "app/public/docs/openapi-v1.json",
    "shared/**", "deploy/**", "app/resources/*", "app/resources/**/*",
    "app/tests/js/*", "app/tests/js/**/*", "app/package.json",
    "app/package-lock.json", "app/scripts/write-build-info.mjs",
    "app/tsconfig.json", "app/vite.config.js", "app/vitest.config.js",
    "scripts/ci_release_contract.py",
    ".github/workflows/*.yml", ".github/workflows/*.yaml",
)
VPS_HEALTH = ("app/scripts/vps-health/**", "ops/vps-health/**")
CI_WATCHER = ("ops/ci-followup-watcher/**",)


def matches(path: str, patterns: tuple[str, ...]) -> bool:
    return any(fnmatch.fnmatchcase(path, pattern) for pattern in patterns)


def classify(paths: list[str]) -> dict[str, bool]:
    return {
        "backend": any(matches(path, BACKEND) for path in paths),
        "frontend": any(matches(path, FRONTEND) for path in paths),
        "deployable": any(matches(path, DEPLOYABLE) for path in paths),
        "vps_health": any(matches(path, VPS_HEALTH) for path in paths),
        "ci_watcher": any(matches(path, CI_WATCHER) for path in paths),
    }


def release_allowed(values: dict[str, str]) -> tuple[bool, str]:
    if values.get("EVENT_NAME") not in {"push", "workflow_dispatch"}:
        return False, "release requires a trusted push or explicit dispatch"
    if values.get("REF_NAME") != "refs/heads/master":
        return False, "release candidate must be refs/heads/master"
    candidate = values.get("CANDIDATE_SHA", "")
    if not re.fullmatch(r"[0-9a-f]{40}", candidate):
        return False, "candidate SHA must be a full commit id"
    if values.get("CHECKED_OUT_SHA") != candidate:
        return False, "checked out commit does not match the candidate SHA"
    if values.get("DEPLOYABLE") != "true":
        return False, "change set is not deployable"
    for area in ("BACKEND", "FRONTEND", "VPS_HEALTH", "CI_WATCHER"):
        applicable = values.get(f"{area}_APPLICABLE")
        result = values.get(f"{area}_RESULT")
        if applicable not in {"true", "false"}:
            return False, f"{area.lower()} applicability is missing or invalid"
        expected = "success" if applicable == "true" else "skipped"
        if result != expected:
            return False, f"{area.lower()} result {result!r}; expected {expected!r}"
    if values.get("AI_RUNTIME_RESULT") != "success":
        return False, f"AI runtime result {values.get('AI_RUNTIME_RESULT')!r}; expected 'success'"
    return True, "trusted exact-SHA candidate passed all applicable canonical checks"


def main() -> int:
    if len(sys.argv) != 2:
        raise SystemExit("usage: ci_release_contract.py classify")
    if sys.argv[1] == "classify":
        paths = [line.strip() for line in sys.stdin if line.strip()]
        for key, value in classify(paths).items():
            print(f"{key}={'true' if value else 'false'}")
        return 0
    if sys.argv[1] == "gate":
        allowed, message = release_allowed(dict(os.environ))
        print(message)
        return 0 if allowed else 1
    raise SystemExit("unknown command")


if __name__ == "__main__":
    raise SystemExit(main())
