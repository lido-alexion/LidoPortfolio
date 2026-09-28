import sys
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from collector.coverage import CoverageAccumulator  # noqa: E402


class CoverageAccumulatorTest(unittest.TestCase):
    def test_duplicate_rows_do_not_inflate_quality_counts(self) -> None:
        coverage = CoverageAccumulator()
        row = {"instrument_id": 1, "minute_timestamp": "2026-09-28T09:15:00+05:30", "coverage_class": "partial"}
        coverage.record([row, dict(row)])
        summary = coverage.summary(expected_minutes=1, instrument_count=1)
        self.assertEqual(1, summary["observed_row_count"])
        self.assertEqual({"partial": 1}, summary["quality_counts"])

    def test_mixed_quality_and_threshold_boundary(self) -> None:
        coverage = CoverageAccumulator()
        coverage.record([
            {"instrument_id": 1, "minute_timestamp": "1", "coverage_class": "partial"},
            {"instrument_id": 1, "minute_timestamp": "2", "coverage_class": "outage"},
            {"instrument_id": 1, "minute_timestamp": "3", "coverage_class": "no_trade"},
            {"instrument_id": 1, "minute_timestamp": "4", "coverage_class": "reconnect_affected"},
        ])
        summary = coverage.summary(expected_minutes=4, instrument_count=1)
        self.assertEqual(100.0, summary["coverage_percent"])
        self.assertEqual(1, summary["quality_counts"]["outage"])


if __name__ == "__main__":
    unittest.main()
