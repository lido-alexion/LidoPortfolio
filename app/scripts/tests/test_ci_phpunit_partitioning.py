"""Regression coverage for exact PHPUnit inventory partitioning."""

from collections import Counter
from pathlib import Path
import contextlib
import io
import importlib.util
import sys
import tempfile
import unittest
from types import SimpleNamespace
import xml.etree.ElementTree as ET

partitioner_path = Path(__file__).resolve().parents[3] / "scripts/run-ci-phpunit-partitioned.py"
partitioner_spec = importlib.util.spec_from_file_location("run_ci_phpunit_partitioned", partitioner_path)
assert partitioner_spec is not None and partitioner_spec.loader is not None
partitioner = importlib.util.module_from_spec(partitioner_spec)
sys.modules[partitioner_spec.name] = partitioner
partitioner_spec.loader.exec_module(partitioner)
PHPUNIT_NAMESPACE = partitioner.PHPUNIT_NAMESPACE
TestFile = partitioner.TestFile
_run_batch = partitioner._run_batch
case_outcomes = partitioner.case_outcomes
load_inventory = partitioner.load_inventory
partition_inventory = partitioner.partition_inventory
run_partitioned = partitioner.run_partitioned
validate_case_outcomes = partitioner.validate_case_outcomes


class PhpunitPartitioningTests(unittest.TestCase):
    def make_test_file(self, root: Path, suite: str, name: str, case_ids: list[str]) -> TestFile:
        path = root / suite / f"{name}Test.php"
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text("<?php // inventory fixture\n")
        return TestFile(path, suite, tuple(case_ids))

    def write_inventory(self, path: Path, files: list[TestFile]) -> None:
        root = ET.Element(f"{{{PHPUNIT_NAMESPACE}}}testSuite")
        tests = ET.SubElement(root, f"{{{PHPUNIT_NAMESPACE}}}tests")
        for index, test_file in enumerate(files):
            test_class = ET.SubElement(
                tests,
                f"{{{PHPUNIT_NAMESPACE}}}testClass",
                {"name": f"Tests\\{test_file.suite}\\Fixture{index}Test", "file": str(test_file.path)},
            )
            for case_id in test_file.case_ids:
                name = case_id.split("::", 1)[1].split("#", 1)[0]
                ET.SubElement(test_class, f"{{{PHPUNIT_NAMESPACE}}}testMethod", {"id": case_id, "name": name})
        ET.ElementTree(root).write(path, encoding="utf-8", xml_declaration=True)

    def test_inventory_expands_provider_cases_and_partitions_whole_files_in_order(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            app = root / "app"
            tests = app / "tests"
            files = [
                self.make_test_file(tests, "Unit", "First", ["Tests\\Unit\\FirstTest::test_data#0", "Tests\\Unit\\FirstTest::test_data#1"]),
                self.make_test_file(tests, "Unit", "Second", [f"Tests\\Unit\\SecondTest::test_{n}" for n in range(2)]),
                self.make_test_file(tests, "Feature", "Third", [f"Tests\\Feature\\ThirdTest::test_{n}" for n in range(2)]),
            ]
            inventory_path = root / "inventory.xml"
            self.write_inventory(inventory_path, files)

            inventory = load_inventory(inventory_path, app)
            batches = partition_inventory(inventory, max_cases=3)

            self.assertEqual([item.path for item in inventory], [item.path for item in files])
            self.assertEqual([sum(len(item.case_ids) for item in batch) for batch in batches], [2, 2, 2])
            self.assertEqual([batch[0].suite for batch in batches], ["Unit", "Unit", "Feature"])
            actual_ids = [case_id for batch in batches for item in batch for case_id in item.case_ids]
            expected_ids = [case_id for item in files for case_id in item.case_ids]
            self.assertEqual(Counter(actual_ids), Counter(expected_ids))

    def test_partition_failure_stops_later_batches_and_preserves_phpunit_status(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            tests = root / "app" / "tests"
            unit_files = [
                self.make_test_file(tests, "Unit", f"Test{index}", [f"Tests\\Unit\\Test{index}::test_{case}" for case in range(100)])
                for index in range(3)
            ]
            feature_file = self.make_test_file(tests, "Feature", "Feature", ["Tests\\Feature\\FeatureTest::test_case"])
            files = [*unit_files, feature_file]
            calls = []

            def fake_run(command, cwd, check):
                calls.append(command)
                if "--log-events-text" in command:
                    event_path = Path(command[command.index("--log-events-text") + 1])
                    if len(calls) == 2:
                        event_path.write_text("Test Prepared (intentional-diagnostic-failure)\n")
                        return SimpleNamespace(returncode=2, stdout=None, stderr=None)
                    start = command.index("phpunit.xml") + 1
                    end = command.index("--log-events-text")
                    selected = set(command[start:end])
                    ids = [case_id for item in files if str(item.path) in selected for case_id in item.case_ids]
                    event_path.write_text("".join(f"Test Finished ({case_id})\n" for case_id in ids))
                return SimpleNamespace(returncode=0, stdout=None, stderr=None)

            with contextlib.redirect_stdout(io.StringIO()), contextlib.redirect_stderr(io.StringIO()):
                status = run_partitioned(files, root / "app", root, run=fake_run, time_binary="fake-time")

            self.assertEqual(status, 2)
            self.assertEqual(len(calls), 2)
            for command in calls:
                self.assertIn("memory_limit=512M", command)
                self.assertIn("--log-events-text", command)

    def test_inventory_rejects_external_test_files_and_empty_selection(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            app = root / "app"
            outside = root / "outside" / "UnexpectedTest.php"
            outside.parent.mkdir()
            outside.write_text("<?php // must not enter PHPUnit selection\n")
            inventory_path = root / "inventory.xml"
            invalid = TestFile(outside, "Feature", ("Tests\\Feature\\UnexpectedTest::test_one",))
            self.write_inventory(inventory_path, [invalid])
            with self.assertRaisesRegex(ValueError, "outside app/tests"):
                load_inventory(inventory_path, app)

            self.write_inventory(inventory_path, [])
            with self.assertRaisesRegex(ValueError, "discovered no test cases"):
                load_inventory(inventory_path, app)

    def test_partition_refuses_to_split_an_oversized_test_file(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            test_file = self.make_test_file(
                root / "app" / "tests",
                "Unit",
                "Oversized",
                [f"Tests\\Unit\\OversizedTest::test_{index}" for index in range(4)],
            )
            with self.assertRaisesRegex(ValueError, "single test file exceeds"):
                partition_inventory([test_file], max_cases=3)

    def test_success_requires_exact_terminal_outcome_inventory(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            test_file = self.make_test_file(root / "app" / "tests", "Unit", "One", ["Tests\\Unit\\OneTest::test_one"])
            event_path = root / "events.txt"

            def check_events(events: str, expected_optional_skips: set[str] | None = None) -> int:
                def fake_run(command, cwd, check):
                    event_path.write_text(events)
                    return SimpleNamespace(returncode=0, stdout=None, stderr=None)

                with contextlib.redirect_stdout(io.StringIO()), contextlib.redirect_stderr(io.StringIO()):
                    return _run_batch(
                        [test_file], 1, 1, event_path, root / "app",
                        run=fake_run, time_binary="fake-time",
                        expected_optional_skips=expected_optional_skips or set(),
                    )

            case_id = "Tests\\Unit\\OneTest::test_one"
            finished = f"Test Finished ({case_id})\n"
            skipped = f"Test Skipped ({case_id})\n"
            self.assertEqual(check_events(finished), 0)
            self.assertEqual(check_events(skipped, {case_id}), 0)  # Skip from setUp: no Test Finished event.
            self.assertEqual(check_events(skipped + finished, {case_id}), 0)  # Runtime skip emits both events.
            self.assertEqual(check_events(skipped), 1)  # New skips require an explicit CI policy entry.
            self.assertEqual(check_events(f"Test Prepared ({case_id})\n"), 1)
            self.assertEqual(check_events(finished + finished), 1)
            self.assertEqual(check_events(finished + f"Test Finished ({case_id}-unexpected)\n"), 1)
            self.assertEqual(check_events(f"Test Errored ({case_id})\n"), 1)

    def test_terminal_outcome_parser_rejects_invalid_skip_lifecycle_sequences(self):
        with tempfile.TemporaryDirectory() as temporary:
            event_path = Path(temporary) / "events.txt"
            case_id = "Tests\\Feature\\FixtureTest::test_skip"
            event_path.write_text(
                f"Test Skipped ({case_id})\n"
                f"Test Finished ({case_id})\n"
                f"Test Errored ({case_id})\n"
            )
            outcomes = case_outcomes(event_path)
            self.assertEqual(outcomes[case_id], ["Skipped", "Finished", "Errored"])
            expected_skips = {case_id}
            self.assertTrue(validate_case_outcomes([case_id], outcomes, expected_skips))
            self.assertTrue(validate_case_outcomes([case_id, "Tests\\Feature\\Other::test_missing"], outcomes, expected_skips))
            self.assertTrue(validate_case_outcomes(
                [case_id],
                {**outcomes, "Tests\\Feature\\Other::test_extra": ["Finished"]},
                expected_skips,
            ))

    def test_failure_incomplete_and_risky_events_are_not_terminal_successes(self):
        with tempfile.TemporaryDirectory() as temporary:
            event_path = Path(temporary) / "events.txt"
            event_types = ["Failed", "Errored", "Marked Incomplete", "Considered Risky"]
            case_ids = [f"Tests\\Feature\\FixtureTest::test_{index}" for index in range(len(event_types))]
            event_path.write_text("".join(
                f"Test {event} ({case_id})\n"
                for event, case_id in zip(event_types, case_ids, strict=True)
            ))
            outcomes = case_outcomes(event_path)
            self.assertEqual([outcomes[case_id][0] for case_id in case_ids], event_types)
            self.assertTrue(validate_case_outcomes(case_ids, outcomes, set()))


if __name__ == "__main__":
    unittest.main()
