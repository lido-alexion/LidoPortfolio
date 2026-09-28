import sys
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from kite_historical_client import (  # noqa: E402
    KiteHistoricalConfig,
    fetch_minute_bars,
    fetch_minute_bars_chunked,
    normalize_kite_candle,
)


class KiteHistoricalClientTest(unittest.TestCase):
    def test_normalize_kite_candle(self):
        bar = normalize_kite_candle(["2024-01-02 09:15:00", 100, 101, 99, 100.5, 1200, 0])
        self.assertEqual(bar["ts"], "2024-01-02 09:15:00")
        self.assertEqual(bar["close"], 100.5)
        self.assertEqual(bar["volume"], 1200)

    def test_fetch_minute_bars_uses_http_get(self):
        cfg = KiteHistoricalConfig(api_key="key", access_token="token", instrument_token=408065)

        def fake_http(url: str, headers: dict[str, str]) -> dict:
            self.assertIn("/instruments/historical/408065/minute", url)
            self.assertIn("Authorization", headers)
            return {
                "status": "success",
                "data": {"candles": [["2024-01-02 09:15:00", 1, 2, 1.5, 1.8, 10, 0]]},
            }

        bars = fetch_minute_bars(cfg, "2024-01-02", "2024-01-02", http_get=fake_http)
        self.assertEqual(len(bars), 1)
        self.assertEqual(bars[0]["open"], 1.0)

    def test_fetch_minute_bars_chunked_merges_windows(self):
        cfg = KiteHistoricalConfig(api_key="key", access_token="token", instrument_token=1)
        calls = []

        def fake_http(url: str, headers: dict[str, str]) -> dict:  # noqa: ARG001
            calls.append(url)
            return {"status": "success", "data": {"candles": [["2024-01-01 09:15:00", 1, 1, 1, 1, 1, 0]]}}

        bars = fetch_minute_bars_chunked(
            cfg,
            "2024-01-01",
            "2024-02-15",
            chunk_days=30,
            http_get=fake_http,
        )
        self.assertGreaterEqual(len(calls), 2)
        self.assertGreaterEqual(len(bars), 2)


if __name__ == "__main__":
    unittest.main()
