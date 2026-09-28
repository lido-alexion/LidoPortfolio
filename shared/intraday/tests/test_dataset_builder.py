import sys
import tempfile
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from dataset_builder import duckdb_symbol_summary, polars_daily_ohlc  # noqa: E402
from parquet_store import write_bars  # noqa: E402

try:
    import duckdb  # noqa: F401
except ImportError:  # pragma: no cover
    duckdb = None

try:
    import polars  # noqa: F401
except ImportError:  # pragma: no cover
    polars = None

try:
    import pyarrow  # noqa: F401
except ImportError:  # pragma: no cover
    pyarrow = None


class DatasetBuilderTest(unittest.TestCase):
    @unittest.skipIf(pyarrow is None or duckdb is None, "pyarrow and duckdb required")
    def test_duckdb_symbol_summary(self):
        with tempfile.TemporaryDirectory() as tmp:
            write_bars(
                tmp,
                "tcs",
                "NSE",
                [
                    {
                        "ts": "2024-04-02 09:15:00",
                        "open": 10,
                        "high": 11,
                        "low": 9,
                        "close": 10.5,
                        "volume": 100,
                    },
                    {
                        "ts": "2024-04-02 09:16:00",
                        "open": 10.5,
                        "high": 11,
                        "low": 10,
                        "close": 10.8,
                        "volume": 50,
                    },
                ],
            )
            summary = duckdb_symbol_summary(tmp, symbol="TCS")
            self.assertEqual(summary[0]["bar_count"], 2)

    @unittest.skipIf(pyarrow is None or polars is None, "pyarrow and polars required")
    def test_polars_daily_ohlc_is_pit_bounded(self):
        with tempfile.TemporaryDirectory() as tmp:
            write_bars(
                tmp,
                "tcs",
                "NSE",
                [
                    {
                        "ts": "2024-04-02 09:15:00",
                        "open": 10,
                        "high": 11,
                        "low": 9,
                        "close": 10.5,
                        "volume": 100,
                    },
                    {
                        "ts": "2024-04-03 09:15:00",
                        "open": 11,
                        "high": 12,
                        "low": 10,
                        "close": 11.5,
                        "volume": 80,
                    },
                ],
            )
            frame = polars_daily_ohlc(tmp, "TCS", through_date="2024-04-02")
            self.assertEqual(frame.height, 1)
            self.assertEqual(frame["session_date"][0], "2024-04-02")


if __name__ == "__main__":
    unittest.main()
