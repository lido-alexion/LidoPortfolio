#!/usr/bin/env python3
"""Run the complete PHPUnit inventory in bounded, sequential PHP processes."""

from __future__ import annotations

from dataclasses import dataclass
import os
from pathlib import Path
import re
import subprocess
import sys
import tempfile
import xml.etree.ElementTree as ET


MAX_CASES_PER_PROCESS = 300
MEMORY_LIMIT = "512M"
PHPUNIT = "vendor/bin/phpunit"
PHPUNIT_NAMESPACE = "https://xml.phpunit.de/testSuite"
ALLOWED_SUITES = {"Unit", "Feature"}


@dataclass(frozen=True)
class TestFile:
    path: Path
    suite: str
    case_ids: tuple[str, ...]


def load_inventory(xml_path: Path, app_dir: Path) -> list[TestFile]:
    """Read PHPUnit's full discovered case inventory, including data sets."""
    tests_root = (app_dir / "tests").resolve()
    files: dict[Path, tuple[str, list[str]]] = {}
    all_ids: set[str] = set()

    root = ET.parse(xml_path).getroot()
    for test_class in root.findall(f".//{{{PHPUNIT_NAMESPACE}}}testClass"):
        raw_path = test_class.get("file")
        class_name = test_class.get("name", "")
        if not raw_path or not class_name:
            raise ValueError("PHPUnit inventory contains a test class without a file or name")

        candidate = Path(raw_path)
        path = (candidate if candidate.is_absolute() else app_dir / candidate).resolve()
        try:
            relative = path.relative_to(tests_root)
        except ValueError as error:
            raise ValueError(f"PHPUnit inventory references a file outside app/tests: {path}") from error
        if not path.is_file() or len(relative.parts) < 2 or relative.parts[0] not in ALLOWED_SUITES:
            raise ValueError(f"PHPUnit inventory references an unsupported test file: {path}")

        suite = relative.parts[0]
        existing = files.get(path)
        if existing is None:
            ids: list[str] = []
            files[path] = (suite, ids)
        else:
            previous_suite, ids = existing
            if previous_suite != suite:
                raise ValueError(f"Test file is assigned to multiple suites: {path}")

        methods = test_class.findall(f"{{{PHPUNIT_NAMESPACE}}}testMethod")
        if not methods:
            raise ValueError(f"PHPUnit inventory contains a test class with no test cases: {class_name}")
        for method in methods:
            case_id = method.get("id")
            if not case_id:
                raise ValueError(f"PHPUnit inventory contains a test case without an id: {class_name}")
            if case_id in all_ids:
                raise ValueError(f"PHPUnit inventory contains a duplicate test case id: {case_id}")
            all_ids.add(case_id)
            ids.append(case_id)

    if not files:
        raise ValueError("PHPUnit discovered no test cases; refusing an empty green run")

    return [TestFile(path, suite, tuple(ids)) for path, (suite, ids) in files.items()]


def partition_inventory(files: list[TestFile], max_cases: int = MAX_CASES_PER_PROCESS) -> list[list[TestFile]]:
    """Group complete test files in discovery order, never mixing suites."""
    if not files:
        raise ValueError("PHPUnit inventory contains no test files; refusing an empty green run")
    if max_cases < 1:
        raise ValueError("max_cases must be positive")

    batches: list[list[TestFile]] = []
    current: list[TestFile] = []
    current_count = 0
    current_suite: str | None = None

    for test_file in files:
        count = len(test_file.case_ids)
        if count > max_cases:
            raise ValueError(
                f"A single test file exceeds the {max_cases}-case process limit: "
                f"{test_file.path} ({count} cases)"
            )
        if current and (current_suite != test_file.suite or current_count + count > max_cases):
            batches.append(current)
            current = []
            current_count = 0
        current.append(test_file)
        current_count += count
        current_suite = test_file.suite

    if current:
        batches.append(current)
    return batches


CASE_EVENT = re.compile(r"^Test (Finished|Skipped|Failed|Errored|Marked Incomplete|Considered Risky|Risky) \((.+)\)$")
EXPECTED_OPTIONAL_SKIP_CASES = {
    "Tests\\Unit\\ViteAssetPathTest::test_vite_entry_urls_are_root_relative_for_subdirectory_deploy",
    "Tests\\Feature\\V8\\MlBoundedTrainingCampaignTest::test_real_adapter_campaign_completes_each_horizon_with_persisted_evidence",
}


def case_outcomes(event_log: Path) -> dict[str, list[str]]:
    """Collect PHPUnit's terminal lifecycle events for each case."""
    outcomes: dict[str, list[str]] = {}
    if not event_log.is_file():
        return outcomes
    for line in event_log.read_text(errors="replace").splitlines():
        match = CASE_EVENT.fullmatch(line)
        if match:
            event, case_id = match.groups()
            outcomes.setdefault(case_id, []).append(event)
    return outcomes


def validate_case_outcomes(
    expected: list[str],
    outcomes: dict[str, list[str]],
    expected_optional_skips: set[str],
) -> list[str]:
    """Require one complete, successful PHPUnit lifecycle for every inventory case."""
    expected_ids = set(expected)
    actual_ids = set(outcomes)
    missing = sorted(expected_ids - actual_ids)
    unexpected = sorted(actual_ids - expected_ids)
    invalid = []
    for case_id, events in outcomes.items():
        if case_id not in expected_ids:
            continue
        sequence = tuple(events)
        if sequence == ("Finished",):
            continue
        if case_id in expected_optional_skips and sequence in {
            ("Skipped",),
            ("Skipped", "Finished"),
        }:
            continue
        invalid.append((case_id, sequence))
    invalid.sort()
    issues = []
    if missing:
        issues.append(f"missing={missing[:10]}")
    if unexpected:
        issues.append(f"unexpected={unexpected[:10]}")
    if invalid:
        issues.append(f"invalid_terminal_sequences={invalid[:10]}")
    return issues


def _emit_captured(result: subprocess.CompletedProcess[str]) -> None:
    if result.stdout:
        print(result.stdout, end="", file=sys.stderr)
    if result.stderr:
        print(result.stderr, end="", file=sys.stderr)


def _run_batch(
    batch: list[TestFile],
    index: int,
    total: int,
    event_log: Path,
    app_dir: Path,
    run=subprocess.run,
    time_binary: str = "/usr/bin/time",
    expected_optional_skips: set[str] = EXPECTED_OPTIONAL_SKIP_CASES,
) -> int:
    expected = [case_id for test_file in batch for case_id in test_file.case_ids]
    command = [
        time_binary,
        "-v",
        "php",
        "-d",
        f"memory_limit={MEMORY_LIMIT}",
        PHPUNIT,
        "-c",
        "phpunit.xml",
        *(str(test_file.path) for test_file in batch),
        "--log-events-text",
        str(event_log),
    ]
    case_count = len(expected)
    suite = batch[0].suite
    print(f"PHPUnit partition {index}/{total}: suite={suite}, discovered_cases={case_count}")
    result = run(command, cwd=app_dir, check=False)
    if result.returncode != 0:
        if result.stdout or result.stderr:
            _emit_captured(result)
        print(f"PHPUnit partition {index}/{total} exited with status {result.returncode}; last recorded events:", file=sys.stderr)
        if event_log.is_file():
            lines = event_log.read_text(errors="replace").splitlines()
            print("\n".join(lines[-100:]), file=sys.stderr)
        return result.returncode

    outcomes = case_outcomes(event_log)
    issues = validate_case_outcomes(expected, outcomes, expected_optional_skips)
    if issues:
        print(
            f"PHPUnit partition {index}/{total} terminal outcome inventory mismatch: "
            f"expected_cases={len(expected)} observed_cases={len(outcomes)} "
            + " ".join(issues),
            file=sys.stderr,
        )
        return 1
    skipped = sum("Skipped" in events for events in outcomes.values())
    print(
        f"PHPUnit partition {index}/{total}: inventory verified "
        f"({len(outcomes)} terminal cases, {skipped} skipped)"
    )
    return 0


def run_partitioned(
    files: list[TestFile],
    app_dir: Path,
    work_dir: Path,
    run=subprocess.run,
    time_binary: str = "/usr/bin/time",
) -> int:
    batches = partition_inventory(files)
    total_cases = sum(len(test_file.case_ids) for test_file in files)
    print(
        f"PHPUnit inventory verified: {total_cases} cases across {len(files)} files; "
        f"{len(batches)} sequential processes, at most {MAX_CASES_PER_PROCESS} cases each"
    )

    for index, batch in enumerate(batches, start=1):
        event_log = work_dir / f"phpunit-events-{index:03d}.txt"
        status = _run_batch(batch, index, len(batches), event_log, app_dir, run, time_binary)
        if status != 0:
            return status
    return 0


def main() -> int:
    app_dir = Path(__file__).resolve().parents[1] / "app"
    if Path.cwd().resolve() != app_dir.resolve():
        print(f"Run partitioned PHPUnit from app directory: {app_dir}", file=sys.stderr)
        return 64
    if not Path("/usr/bin/time").is_file():
        print("Hosted PHPUnit partitioning requires GNU /usr/bin/time", file=sys.stderr)
        return 1

    temp_root = Path(os.environ.get("RUNNER_TEMP") or tempfile.gettempdir())
    temp_root.mkdir(parents=True, exist_ok=True)
    with tempfile.TemporaryDirectory(prefix="stox-phpunit-partitions-", dir=temp_root) as temporary:
        work_dir = Path(temporary)
        inventory_path = work_dir / "phpunit-inventory.xml"
        command = [
            "php",
            "-d",
            f"memory_limit={MEMORY_LIMIT}",
            PHPUNIT,
            "-c",
            "phpunit.xml",
            "--list-tests-xml",
            str(inventory_path),
        ]
        inventory_result = subprocess.run(command, cwd=app_dir, check=False, capture_output=True, text=True)
        if inventory_result.returncode != 0:
            _emit_captured(inventory_result)
            print(f"PHPUnit inventory command failed with status {inventory_result.returncode}", file=sys.stderr)
            return inventory_result.returncode
        try:
            files = load_inventory(inventory_path, app_dir)
        except (ET.ParseError, OSError, ValueError) as error:
            print(f"Unable to validate PHPUnit inventory: {error}", file=sys.stderr)
            return 1
        return run_partitioned(files, app_dir, work_dir)


if __name__ == "__main__":
    raise SystemExit(main())
