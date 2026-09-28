import os
import sys
import tempfile
import unittest
from datetime import datetime, timedelta, timezone
from pathlib import Path
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from collector.collector_app import CollectorApp  # noqa: E402
from collector.finalization_state import FinalizationState  # noqa: E402


class CollectorLifecycleTest(unittest.TestCase):
    def test_fixed_date_session_phases_are_deterministic(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            app = CollectorApp(Path(tmp) / "command.json", Path(tmp) / "heartbeat.json", Path(tmp) / "data")
            tz = app._market_timezone
            self.assertEqual("pre_market", app._session_phase(datetime(2026, 9, 28, 3, 30, tzinfo=timezone.utc)))
            self.assertEqual("market", app._session_phase(datetime(2026, 9, 28, 5, 0, tzinfo=timezone.utc)))
            self.assertEqual("post_market", app._session_phase(datetime(2026, 9, 28, 11, 0, tzinfo=timezone.utc)))
            self.assertEqual("Asia/Kolkata", str(tz))

    def test_finalization_and_backup_state_survive_restart_boundaries(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            state_path = Path(tmp) / "finalization.json"
            state = FinalizationState(state_path)
            state.mark_finalization_started("2026-09-28")
            state.mark_finalization_failed("write interrupted")
            restarted = FinalizationState(state_path)
            self.assertEqual("finalization_failed", restarted.load()["status"])
            restarted.mark_finalization_started("2026-09-28")
            restarted.mark_finalized("2026-09-28", 3)
            restarted.mark_backup("backup_failed", "destination unavailable")
            self.assertEqual("backup_failed", FinalizationState(state_path).load()["status"])
            self.assertTrue(FinalizationState(state_path).retry_allowed(datetime.now(timezone.utc) + timedelta(minutes=6)))
            restarted.mark_backup("ok:2026-09-28")
            self.assertEqual("finalized", FinalizationState(state_path).load()["status"])

    def test_manual_hold_is_local_stop_until_explicit_start(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            app = CollectorApp(Path(tmp) / "command.json", Path(tmp) / "heartbeat.json", Path(tmp) / "data")
            app.handle_command("stop")
            self.assertTrue(app.manual_stop)
            self.assertEqual("manually_stopped", app.collector_state)
            app.handle_command("start")
            self.assertFalse(app.manual_stop)
            self.assertEqual("starting", app.collector_state)


if __name__ == "__main__":
    unittest.main()
