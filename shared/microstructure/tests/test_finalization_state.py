import sys
import tempfile
import unittest
from datetime import datetime, timedelta, timezone
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from collector.finalization_state import FinalizationState  # noqa: E402


class FinalizationStateTest(unittest.TestCase):
    def test_state_is_atomic_and_survives_reload(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp) / "state.json"
            state = FinalizationState(path)
            state.mark_finalization_started("2026-09-28")
            state.mark_finalized("2026-09-28", 12)
            self.assertEqual(FinalizationState(path).load()["row_count"], 12)
            self.assertEqual(list(Path(tmp).glob("*.staging")), [])

    def test_retry_is_bounded_and_backoff_applies(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            state = FinalizationState(Path(tmp) / "state.json", max_retries=2)
            state.mark_finalization_started("2026-09-28")
            state.mark_finalization_failed("temporary")
            current = datetime.now(timezone.utc) + timedelta(minutes=1)
            self.assertTrue(state.retry_allowed(current))
            state.mark_finalization_started("2026-09-28")
            state.mark_finalization_failed("again")
            self.assertFalse(state.retry_allowed(current + timedelta(minutes=10)))


if __name__ == "__main__":
    unittest.main()
