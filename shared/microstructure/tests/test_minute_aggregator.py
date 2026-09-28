import sys
import unittest
from datetime import datetime, timezone
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from collector.minute_aggregator import MinuteAggregator  # noqa: E402


class MinuteAggregatorTest(unittest.TestCase):
    def test_connected_no_tick_is_not_an_outage(self) -> None:
        agg = MinuteAggregator()
        meta = {"instrument_id": 8, "source_instrument_token": 108, "exchange": "NSE", "tradingsymbol": "QUIET"}
        at = datetime(2026, 9, 27, 10, 15, 30, tzinfo=timezone.utc)
        agg.ensure_instrument(meta, at=at)
        row = agg.flush_before(datetime(2026, 9, 27, 10, 16, tzinfo=timezone.utc))[0]
        self.assertEqual("no_trade", row["coverage_class"])

    def test_reconnect_mid_minute_remains_distinct_after_ticks_resume(self) -> None:
        agg = MinuteAggregator()
        meta = {"instrument_id": 9, "source_instrument_token": 109, "exchange": "NSE", "tradingsymbol": "RECON"}
        at = datetime(2026, 9, 27, 10, 15, 5, tzinfo=timezone.utc)
        agg.ingest(meta, {"last_price": 100.0}, at=at)
        agg.mark_collection_gap("reconnect_window")
        agg.ingest(meta, {"last_price": 101.0}, at=at)
        row = agg.flush_before(datetime(2026, 9, 27, 10, 16, tzinfo=timezone.utc))[0]
        self.assertEqual("reconnect_affected", row["coverage_class"])
        self.assertEqual("reconnect_window", row["partial_reason"])

    def test_multiple_reconnects_do_not_overclaim_full_coverage(self) -> None:
        agg = MinuteAggregator()
        meta = {"instrument_id": 10, "source_instrument_token": 110, "exchange": "NSE", "tradingsymbol": "MULTI"}
        at = datetime(2026, 9, 27, 10, 15, 5, tzinfo=timezone.utc)
        agg.ingest(meta, {"last_price": 100.0}, at=at)
        agg.mark_collection_gap("reconnect_window")
        agg.mark_collection_gap("reconnect_window")
        row = agg.flush_before(datetime(2026, 9, 27, 10, 16, tzinfo=timezone.utc))[0]
        self.assertNotEqual("complete", row["coverage_class"])
        self.assertEqual("reconnect_affected", row["coverage_class"])
    def test_active_instrument_without_tick_is_retained_as_no_trade(self) -> None:
        agg = MinuteAggregator()
        meta = {
            "instrument_id": 3,
            "source_instrument_token": 101,
            "exchange": "NSE",
            "tradingsymbol": "QUIET",
        }
        at = datetime(2026, 9, 27, 10, 15, 30, tzinfo=timezone.utc)
        agg.ensure_instrument(meta, at=at)
        rows = agg.flush_before(datetime(2026, 9, 27, 10, 16, 0, tzinfo=timezone.utc))
        self.assertEqual(1, len(rows))
        self.assertEqual("no_trade", rows[0]["coverage_class"])
        self.assertEqual("no_update", rows[0]["partial_reason"])
        self.assertIsNone(rows[0]["close"])

    def test_ingest_and_flush(self) -> None:
        agg = MinuteAggregator()
        meta = {
            "instrument_id": 1,
            "source_instrument_token": 99,
            "exchange": "NSE",
            "tradingsymbol": "RELIANCE",
        }
        at = datetime(2026, 9, 27, 10, 15, 30, tzinfo=timezone.utc)
        agg.ingest(meta, {"last_price": 100.0, "volume_traded": 1000}, at=at)
        agg.ingest(meta, {"last_price": 101.0, "volume_traded": 1100}, at=at)
        rows = agg.flush_before(datetime(2026, 9, 27, 10, 16, 0, tzinfo=timezone.utc))
        self.assertEqual(1, len(rows))
        self.assertEqual(100.0, rows[0]["open"])
        self.assertEqual(101.0, rows[0]["close"])
        self.assertEqual(2, rows[0]["tick_count"])
        self.assertEqual("partial", rows[0]["coverage_class"])

    def test_disconnect_marks_observed_and_unobserved_minutes_differently(self) -> None:
        agg = MinuteAggregator()
        observed = {"instrument_id": 1, "source_instrument_token": 99, "exchange": "NSE", "tradingsymbol": "OBS"}
        quiet = {"instrument_id": 2, "source_instrument_token": 100, "exchange": "NSE", "tradingsymbol": "QUIET"}
        at = datetime(2026, 9, 27, 10, 15, 30, tzinfo=timezone.utc)
        agg.ingest(observed, {"last_price": 100.0}, at=at)
        agg.ensure_instrument(quiet, at=at)
        agg.mark_collection_gap("collector_disconnected")
        rows = agg.flush_before(datetime(2026, 9, 27, 10, 16, 0, tzinfo=timezone.utc))
        quality = {row["tradingsymbol"]: row for row in rows}
        self.assertEqual("reconnect_affected", quality["OBS"]["coverage_class"])
        self.assertEqual("outage", quality["QUIET"]["coverage_class"])

    def test_microprice_and_oi_fields(self) -> None:
        agg = MinuteAggregator()
        meta = {
            "instrument_id": 2,
            "source_instrument_token": 100,
            "exchange": "NFO",
            "tradingsymbol": "NIFTY24SEPFUT",
        }
        at = datetime(2026, 9, 27, 10, 20, 0, tzinfo=timezone.utc)
        tick = {
            "last_price": 200.0,
            "volume_traded": 5000,
            "last_traded_quantity": 10,
            "average_traded_price": 199.9,
            "oi": 10000,
            "depth": {
                "buy": [{"price": 199.5, "quantity": 100, "orders": 2}],
                "sell": [{"price": 200.5, "quantity": 300, "orders": 4}],
            },
        }
        agg.ingest(meta, tick, at=at)
        tick["oi"] = 10100
        tick["last_price"] = 201.0
        tick["last_traded_quantity"] = 20
        tick["average_traded_price"] = 200.4
        tick["depth"] = {
            "buy": [{"price": 200.0, "quantity": 50, "orders": 1}],
            "sell": [{"price": 201.0, "quantity": 80, "orders": 2}],
        }
        agg.ingest(meta, tick, at=at)
        rows = agg.flush_before(datetime(2026, 9, 27, 10, 21, 0, tzinfo=timezone.utc))
        self.assertEqual(1, len(rows))
        self.assertIsNotNone(rows[0]["microprice_close"])
        self.assertEqual(10100.0, rows[0]["oi_close"])
        self.assertEqual(100.0, rows[0]["oi_delta"])
        self.assertIsNotNone(rows[0]["min_spread_abs"])
        self.assertIsNotNone(rows[0]["max_spread_abs"])
        self.assertEqual(3.0, rows[0]["bid_order_count_sum"])
        self.assertEqual(6.0, rows[0]["ask_order_count_sum"])
        self.assertEqual(30.0, rows[0]["last_traded_quantity_sum"])
        self.assertEqual(15.0, rows[0]["last_traded_quantity_avg"])
        self.assertEqual(20.0, rows[0]["last_traded_quantity_max"])
        self.assertAlmostEqual(200.15, rows[0]["average_traded_price_avg"])
        self.assertAlmostEqual(-0.3333333333, rows[0]["order_count_imbalance_5"])


if __name__ == "__main__":
    unittest.main()
