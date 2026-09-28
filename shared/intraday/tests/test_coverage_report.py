import sys
import tempfile
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from coverage_report import scan_corpus  # noqa: E402
from parquet_store import write_bars  # noqa: E402

try:
    import pyarrow  # noqa: F401
except ImportError:  # pragma: no cover
    pyarrow = None


class CoverageReportTest(unittest.TestCase):
    def test_scan_empty_root(self):
        with tempfile.TemporaryDirectory() as tmp:
            report = scan_corpus(tmp)
            self.assertEqual(report["parquet_files"], 0)
            self.assertEqual(report["totals"]["bars"], 0)

    @unittest.skipIf(pyarrow is None, "pyarrow not installed")
    def test_scan_counts_written_bars(self):
        with tempfile.TemporaryDirectory() as tmp:
            write_bars(
                tmp,
                "infy",
                "NSE",
                [
                    {
                        "ts": "2024-03-01T09:15:00",
                        "open": 1,
                        "high": 2,
                        "low": 1,
                        "close": 1.5,
                        "volume": 5,
                    },
                ],
            )
            report = scan_corpus(tmp)
            self.assertEqual(report["totals"]["bars"], 1)
            self.assertEqual(report["symbols"][0]["symbol"], "INFY")


if __name__ == "__main__":
    unittest.main()
