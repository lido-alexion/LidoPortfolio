import sys
import unittest
from pathlib import Path
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from backfill_worker import WorkerConfig, post_checkpoint, run_once, run_universe  # noqa: E402

try:
    import pyarrow  # noqa: F401
except ImportError:  # pragma: no cover
    pyarrow = None


class BackfillWorkerTest(unittest.TestCase):
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
        cfg = WorkerConfig(api_base="http://example.test/api", token="", corpus_root="/tmp", dry_run=True)
        with patch("backfill_worker.run_once", side_effect=lambda symbol, *_args, **_kwargs: {"symbol": symbol}):
            result = run_universe(["zeta", "ALPHA", "zeta"], "2024-01-01", "2024-01-31", cfg)
        self.assertEqual(result, [{"symbol": "ALPHA"}, {"symbol": "ZETA"}])

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
