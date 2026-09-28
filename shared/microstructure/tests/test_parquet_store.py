import sys
import tempfile
import unittest
from datetime import date
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from collector.parquet_store import append_rows, partition_dir, validate_finalized_partition, write_finalization_manifest  # noqa: E402
from collector.schema_v1 import ALL_FIELDS, SCHEMA_VERSION  # noqa: E402

try:
    import pyarrow.parquet as pq
except ImportError:  # pragma: no cover
    pq = None


class MicrostructureParquetStoreTest(unittest.TestCase):
    def test_partition_dir_includes_schema_and_trading_day(self) -> None:
        root = Path("/data/micro")
        path = partition_dir(root, date(2026, 9, 27))
        self.assertEqual(path, root / SCHEMA_VERSION / "year=2026" / "month=09" / "date=2026-09-27")

    @unittest.skipIf(pq is None, "pyarrow not installed")
    def test_append_rows_persists_schema_v1_columns(self) -> None:
        row = {field: None for field in ALL_FIELDS}
        row.update(
            {
                "instrument_id": 1,
                "source_instrument_token": 99,
                "exchange": "NSE",
                "tradingsymbol": "RELIANCE",
                "minute_timestamp": "2026-09-27T10:15:00+05:30",
                "source": "kite_full_ws",
                "schema_version": SCHEMA_VERSION,
                "open": 100.0,
                "high": 101.0,
                "low": 99.5,
                "close": 100.5,
                "volume_delta": 1200,
                "tick_count": 42,
                "microprice_close": 100.25,
                "min_spread_abs": 0.5,
                "max_spread_abs": 1.0,
                "bid_order_count_sum": 3.0,
                "ask_order_count_sum": 4.0,
                "oi_close": 5000.0,
            }
        )
        with tempfile.TemporaryDirectory() as tmp:
            written = append_rows(Path(tmp), date(2026, 9, 27), [row], "part-0001.parquet")
            self.assertIsNotNone(written)
            table = pq.read_table(written)
            for field in ("microprice_close", "min_spread_abs", "max_spread_abs", "oi_close", "bid_order_count_sum"):
                self.assertIn(field, table.column_names)

    @unittest.skipIf(pq is None, "pyarrow not installed")
    def test_corrupt_partition_is_rejected_even_when_manifest_exists(self) -> None:
        row = {field: None for field in ALL_FIELDS}
        row.update({"instrument_id": 1, "source_instrument_token": 99, "exchange": "NSE", "tradingsymbol": "X", "minute_timestamp": "2026-09-27T10:15:00+05:30", "source": "kite_full_ws", "schema_version": SCHEMA_VERSION})
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            path = append_rows(root, date(2026, 9, 27), [row], "part-0001.parquet")
            write_finalization_manifest(root, date(2026, 9, 27), {"row_count": 1})
            validate_finalized_partition(root, date(2026, 9, 27))
            path.write_bytes(b"not parquet")
            with self.assertRaises((ValueError, OSError)):
                validate_finalized_partition(root, date(2026, 9, 27))


if __name__ == "__main__":
    unittest.main()
