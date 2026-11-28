import importlib.util
import unittest
from pathlib import Path
from unittest.mock import patch


MONITOR_PATH = Path(__file__).parents[3] / "app" / "scripts" / "vps-health" / "monitor.py"
SPEC = importlib.util.spec_from_file_location("vps_health_monitor", MONITOR_PATH)
monitor = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(monitor)


class Entry:
    def __init__(self, name, size=0):
        self.name = name
        self.path = name
        self.size = size

    def is_symlink(self):
        return False

    def is_file(self, follow_symlinks=False):
        return True

    def is_dir(self, follow_symlinks=False):
        return False

    def stat(self, follow_symlinks=False):
        return type("Stat", (), {"st_size": self.size})()


class Scan:
    def __init__(self, entries):
        self.entries = entries

    def __enter__(self):
        return iter(self.entries)

    def __exit__(self, *_args):
        return False


class DirectoryBreakdownTest(unittest.TestCase):
    def test_budget_is_shared_fairly_and_hidden_paths_stay_unavailable(self):
        children = {
            "/var/log": [Entry(f"log-{i}", 2) for i in range(500)],
            "/var/lib": [Entry("db", 3)],
            "/var/cache": [Entry("cache", 5)],
            "/var/tmp": [],
            "/opt": [Entry("app", 7)],
        }
        with patch.object(monitor.os, "scandir", side_effect=lambda path: Scan(children[path])), patch.object(monitor.time, "monotonic", return_value=0):
            result = monitor.bounded_directory_breakdown(budget_entries=10, budget_seconds=100)

        paths = {item["path"]: item for item in result["paths"]}
        self.assertEqual("partial", paths["/var/log"]["status"])
        self.assertEqual(4, paths["/var/log"]["shallow_file_bytes"])
        self.assertEqual("available", paths["/var/lib"]["status"])
        self.assertEqual(3, paths["/var/lib"]["shallow_file_bytes"])
        self.assertEqual("available", paths["/var/tmp"]["status"])
        self.assertEqual(0, paths["/var/tmp"]["shallow_file_bytes"])
        self.assertEqual("unavailable", paths["/tmp"]["status"])
        self.assertIsNone(paths["/tmp"]["shallow_file_bytes"])
        self.assertEqual("unavailable", paths["/home"]["status"])
        self.assertEqual(5, result["entries_scanned"])

    def test_categories_never_reached_at_global_limit_are_null_and_unavailable(self):
        children = {"/var/log": [Entry("log", 8)], "/var/lib": [Entry("db", 9)]}
        with patch.object(monitor.os, "scandir", side_effect=lambda path: Scan(children[path])), patch.object(monitor.time, "monotonic", return_value=0):
            result = monitor.bounded_directory_breakdown(budget_entries=1, budget_seconds=100)

        paths = {item["path"]: item for item in result["paths"]}
        self.assertEqual("available", paths["/var/log"]["status"])
        self.assertEqual(8, paths["/var/log"]["shallow_file_bytes"])
        self.assertEqual("unavailable", paths["/var/cache"]["status"])
        self.assertIsNone(paths["/var/cache"]["shallow_file_bytes"])
        candidate = next(item for item in result["reclaimable_candidates"] if item["kind"] == "cache_files")
        self.assertEqual("unavailable", candidate["status"])
        self.assertIsNone(candidate["bytes"])


if __name__ == "__main__":
    unittest.main()
