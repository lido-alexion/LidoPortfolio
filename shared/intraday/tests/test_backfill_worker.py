import sys
import unittest
from pathlib import Path
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from backfill_worker import WorkerConfig, plan_windows, post_checkpoint, run_index_map, run_once, run_planned, run_universe  # noqa: E402

try:
    import pyarrow  # noqa: F401
except ImportError:  # pragma: no cover
    pyarrow = None


class BackfillWorkerTest(unittest.TestCase):
    def test_plan_windows_is_deterministic_and_bounded(self):
        self.assertEqual(plan_windows("2024-01-01", "2024-02-05", 30), [
            ("2024-01-01", "2024-01-30"),
            ("2024-01-31", "2024-02-05"),
        ])

    def test_planned_run_skips_completed_windows_and_resumes_first_incomplete(self):
        cfg = WorkerConfig(api_base="http://example.test/api", token="secret", corpus_root="/tmp", dry_run=False, window_days=2)
        checkpoints = {
            ("2024-01-01", "2024-01-02"): {"status": "complete"},
        }
        with patch("backfill_worker.run_once", side_effect=lambda symbol, start, end, cfg, **kwargs: {
            "status": "complete", "symbol": symbol, "window_start": start, "window_end": end,
        }) as run:
            result = run_planned(
                "INFY", "2024-01-01", "2024-01-05", cfg,
                checkpoint_reader=lambda unit: checkpoints.get((unit.window_start, unit.window_end)),
                pause_reader=lambda: False,
            )
        self.assertEqual(result[0]["status"], "skipped")
        self.assertEqual([call.args[1:3] for call in run.call_args_list], [
            ("2024-01-03", "2024-01-04"),
            ("2024-01-05", "2024-01-05"),
        ])

    def test_planned_run_honours_durable_pause_before_next_unit(self):
        cfg = WorkerConfig(api_base="http://example.test/api", token="secret", corpus_root="/tmp", dry_run=False, window_days=2)
        calls = {"count": 0}
        def paused_after_first():
            calls["count"] += 1
            return calls["count"] > 1
        with patch("backfill_worker.run_once", return_value={"status": "complete"}):
            result = run_planned(
                "INFY", "2024-01-01", "2024-01-05", cfg,
                checkpoint_reader=lambda _unit: None,
                pause_reader=paused_after_first,
            )
        self.assertEqual(len(result), 2)
        self.assertEqual(result[1]["status"], "paused")

    def test_dry_run_does_not_call_network(self):
        cfg = WorkerConfig(api_base="http://example.test/api", token="", corpus_root="/tmp", dry_run=True)
        result = post_checkpoint(cfg, {"symbol": "INFY", "status": "pending"})
        self.assertTrue(result["dry_run"])
        self.assertEqual(result["payload"]["symbol"], "INFY")

    def test_run_once_builds_checkpoint_payload(self):
        cfg = WorkerConfig(api_base="http://example.test/api", token="", corpus_root="/tmp", dry_run=True)
        result = run_once("infy", "2024-01-01", "2024-01-31", cfg)
        self.assertIn("payload", result)
        self.assertEqual(result["payload"]["symbol"], "INFY")

    def test_run_universe_is_sorted_deduplicated_and_checkpointed(self):
        cfg = WorkerConfig(api_base="http://example.test/api", token="", corpus_root="/tmp", dry_run=True, window_days=31)
        with patch("backfill_worker.run_once", side_effect=lambda symbol, *_args, **_kwargs: {"symbol": symbol}):
            result = run_universe(["zeta", "ALPHA", "zeta"], "2024-01-01", "2024-01-31", cfg)
        self.assertEqual(result, [{"symbol": "ALPHA"}, {"symbol": "ZETA"}])

    def test_run_index_map_preserves_index_exchange_and_tokens(self):
        cfg = WorkerConfig(api_base="http://example.test/api", token="", corpus_root="/tmp", dry_run=True, window_days=31)
        with patch("backfill_worker.run_once", side_effect=lambda symbol, *_args, **kwargs: {
            "symbol": symbol,
            "exchange": kwargs["exchange"],
            "token": kwargs["kite"].instrument_token,
        }):
            result = run_index_map({"NIFTY 50": 256265, "NIFTY IT": 264969}, "2024-01-01", "2024-01-31", cfg)
        self.assertEqual(result, [
            {"symbol": "NIFTY 50", "exchange": "NSE_INDEX", "token": 256265},
            {"symbol": "NIFTY IT", "exchange": "NSE_INDEX", "token": 264969},
        ])

    @unittest.skipIf(pyarrow is None, "pyarrow not installed")
    def test_run_once_apply_writes_bars_and_marks_complete(self):
        import tempfile

        with tempfile.TemporaryDirectory() as tmp:
            cfg = WorkerConfig(api_base="http://example.test/api", token="secret", corpus_root=tmp, dry_run=False)
            bars = [
                {
                    "ts": "2024-02-01T10:00:00",
                    "open": 1,
                    "high": 2,
                    "low": 1,
                    "close": 1.5,
                    "volume": 10,
                },
            ]
            with patch("backfill_worker.post_checkpoint", side_effect=lambda _cfg, payload: {"payload": payload}):
                result = run_once("infy", "2024-02-01", "2024-02-01", cfg, bars=bars)
            self.assertEqual(result["payload"]["bars_written"], 1)
            self.assertEqual(result["payload"]["status"], "complete")

    def test_run_once_reports_failed_window_for_provider_error(self):
        cfg = WorkerConfig(api_base="http://example.test/api", token="secret", corpus_root="/tmp", dry_run=False)
        kite = type("Kite", (), {"api_key": "key", "access_token": "token", "instrument_token": 1})()
        with patch("backfill_worker.fetch_minute_bars_chunked", side_effect=TimeoutError("provider timeout")):
            with patch("backfill_worker.post_checkpoint", side_effect=lambda _cfg, payload: {"payload": payload}):
                result = run_once("infy", "2024-02-01", "2024-02-01", cfg, kite=kite)
        self.assertEqual(result["payload"]["status"], "failed")
        self.assertEqual(result["payload"]["last_error"]["type"], "TimeoutError")


if __name__ == "__main__":
    unittest.main()
