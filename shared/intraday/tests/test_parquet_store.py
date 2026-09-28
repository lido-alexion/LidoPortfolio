import sys
import tempfile
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from parquet_store import partition_dir, write_bars  # noqa: E402

try:
    import pyarrow.parquet as pq
except ImportError:  # pragma: no cover
    pq = None


class ParquetStoreTest(unittest.TestCase):
    def test_partition_dir_follows_schema_v1_layout(self):
        from datetime import date

        root = Path("/tmp/corpus")
        path = partition_dir(root, date(2024, 3, 15))
        self.assertEqual(path, root / "schema_v1" / "year=2024" / "month=03")

    @unittest.skipIf(pq is None, "pyarrow not installed")
    def test_write_bars_creates_parquet_under_month_partition(self):
        with tempfile.TemporaryDirectory() as tmp:
            bars = [
                {
                    "ts": "2024-01-02T09:15:00+05:30",
                    "open": 100,
                    "high": 101,
                    "low": 99,
                    "close": 100.5,
                    "volume": 1200,
                },
                {
                    "ts": "2024-01-02T09:16:00+05:30",
                    "open": 100.5,
                    "high": 102,
                    "low": 100,
                    "close": 101,
                    "volume": 800,
                },
            ]
            written = write_bars(tmp, "reliance", "NSE", bars)
            self.assertEqual(written, 2)
            parquet_files = list(Path(tmp).rglob("*.parquet"))
            self.assertEqual(len(parquet_files), 1)
            table = pq.read_table(parquet_files[0])
            self.assertEqual(table.num_rows, 2)

    @unittest.skipIf(pq is None, "pyarrow not installed")
    def test_repeated_write_is_idempotent_and_later_window_does_not_overwrite_prior_rows(self):
        with tempfile.TemporaryDirectory() as tmp:
            first = [{"ts": "2024-01-02T09:15:00+05:30", "open": 100, "high": 101, "low": 99, "close": 100, "volume": 10}]
            second = [{"ts": "2024-01-02T09:16:00+05:30", "open": 101, "high": 102, "low": 100, "close": 101, "volume": 20}]
            self.assertEqual(write_bars(tmp, "reliance", "NSE", first), 1)
            self.assertEqual(write_bars(tmp, "reliance", "NSE", first), 1)
            self.assertEqual(write_bars(tmp, "reliance", "NSE", second), 2)
            parquet_files = list(Path(tmp).rglob("*.parquet"))
            self.assertEqual(len(parquet_files), 1)
            self.assertEqual(pq.read_table(parquet_files[0]).num_rows, 2)


if __name__ == "__main__":
    unittest.main()
