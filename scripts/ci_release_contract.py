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
    # These files are the mandatory post-deploy authenticated production smoke
    # and its direct policy/selector/diagnostic dependencies. Keep generic e2e
    # changes frontend-tested without making them release-triggering.
    "app/tests/e2e/production-smoke.spec.js",
    "app/tests/e2e/authenticatedProductionSmoke.mjs",
    "app/tests/e2e/productionSmokeDiagnostics.js",
    "app/tests/e2e/productionSmokePolicy.js",
    "app/tests/e2e/productionSmokeSelectors.js",
    # The feature-specific Kite header/gating E2E contract is a release gate too.
    "app/tests/e2e/kite-header-connect.spec.js",
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


def readiness_allowed(values: dict[str, str]) -> tuple[bool, str]:
    """Validate exact current PR/master evidence before a candidate can be ready."""
    if values.get("CHANGES_RESULT") != "success":
        return False, "changed-scope job did not succeed"
    event = values.get("EVENT_NAME", "")
    ref = values.get("REF_NAME", "")
    action = values.get("PR_ACTION", "")
    draft = values.get("PR_DRAFT", "")
    candidate = values.get("CANDIDATE_SHA", "")
    checked_out = values.get("CHECKED_OUT_SHA", "")
    current_head = values.get("CURRENT_HEAD_SHA", "")
    current_base = values.get("CURRENT_BASE_SHA", "")
    merge_parents = values.get("CHECKED_OUT_PARENTS", "").split()
    if event == "pull_request":
        if not re.fullmatch(r"[1-9][0-9]*", values.get("PR_NUMBER", "")) or ref != f'refs/pull/{values["PR_NUMBER"]}/merge':
            return False, "pull request candidate must use its synthetic merge ref"
        if action not in {"opened", "reopened", "synchronize", "ready_for_review", "converted_to_draft"}:
            return False, f"unsupported pull request action {action!r}"
        if draft != "false" or action == "converted_to_draft":
            return False, "draft pull requests cannot report readiness success"
        if not re.fullmatch(r"[0-9a-f]{40}", current_head):
            return False, "current pull request head SHA is missing or invalid"
        if not re.fullmatch(r"[0-9a-f]{40}", current_base):
            return False, "current pull request base SHA is missing or invalid"
        if values.get("CURRENT_PR_NUMBER") != values.get("PR_NUMBER"):
            return False, "live pull request identity differs from the event pull request"
        if (values.get("CURRENT_PR_STATE") != "open" or values.get("CURRENT_PR_DRAFT") != "false"
                or values.get("CURRENT_BASE_REF") != "master"
                or values.get("CURRENT_BASE_REPO") != values.get("GITHUB_REPOSITORY")):
            return False, "live pull request must be open, ready, and target master"
        if values.get("EVENT_HEAD_SHA") != current_head:
            return False, "event head is stale or differs from the current pull request head"
        if not re.fullmatch(r"[0-9a-f]{40}", candidate) or values.get("WORKFLOW_SHA") != candidate or checked_out != candidate:
            return False, "checked out commit does not match the workflow candidate SHA"
        if len(merge_parents) != 2 or set(merge_parents) != {current_base, current_head}:
            return False, "checked out synthetic merge parents do not match the current live base and head"
    elif event == "push" or event == "workflow_dispatch":
        if ref != "refs/heads/master":
            return False, "master readiness requires refs/heads/master"
        if not re.fullmatch(r"[0-9a-f]{40}", candidate) or checked_out != candidate:
            return False, "master candidate SHA is missing, invalid, or mismatched"
        if values.get("WORKFLOW_SHA") != candidate:
            return False, "workflow SHA does not match the checked out master candidate"
    else:
        return False, f"unsupported event {event!r}"
    if values.get("DEPLOYABLE") not in {"true", "false"}:
        return False, "changed-scope evidence is missing or invalid"
    for area in ("BACKEND", "FRONTEND", "VPS_HEALTH", "CI_WATCHER"):
        applicable = values.get(f"{area}_APPLICABLE")
        result = values.get(f"{area}_RESULT")
        if applicable not in {"true", "false"}:
            return False, f"{area.lower()} applicability is missing or invalid"
        expected = "success" if applicable == "true" else "skipped"
        if result != expected:
            return False, f"{area.lower()} result {result!r}; expected {expected!r}"
    if values.get("AI_RUNTIME_RESULT") != "success":
        return False, "AI runtime check did not succeed"
    if event == "pull_request" and values.get("PACKAGE_RESULT") != "skipped":
        return False, "pull request packaging must remain disabled"
    if event in {"push", "workflow_dispatch"}:
        expected_package = "success" if values.get("DEPLOYABLE") == "true" else "skipped"
        if values.get("PACKAGE_RESULT") != expected_package:
            return False, f"master packaging result {values.get('PACKAGE_RESULT')!r}; expected {expected_package!r}"
    return True, "current candidate passed readiness evidence checks"


def main() -> int:
    if len(sys.argv) != 2:
        raise SystemExit("usage: ci_release_contract.py classify|gate|readiness")
    if sys.argv[1] == "classify":
        paths = [line.strip() for line in sys.stdin if line.strip()]
        for key, value in classify(paths).items():
            print(f"{key}={'true' if value else 'false'}")
        return 0
    if sys.argv[1] == "gate":
        allowed, message = release_allowed(dict(os.environ))
        print(message)
        return 0 if allowed else 1
    if sys.argv[1] == "readiness":
        allowed, message = readiness_allowed(dict(os.environ))
        print(message)
        return 0 if allowed else 1
    raise SystemExit("unknown command")


if __name__ == "__main__":
    raise SystemExit(main())
