import json
import sys
import tempfile
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from collector.universe_audit import UniverseAudit  # noqa: E402


class UniverseAuditTest(unittest.TestCase):
    def test_refresh_records_add_remove_and_mapping_changes(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            audit = UniverseAudit(Path(tmp) / "universe.json")
            audit.record([{"instrument_id": 1, "source_instrument_token": 10, "tradingsymbol": "A"}, {"instrument_id": 2, "source_instrument_token": 20, "tradingsymbol": "B"}], "bootstrap")
            latest = audit.record([{"instrument_id": 1, "source_instrument_token": 11, "tradingsymbol": "A2"}, {"instrument_id": 3, "source_instrument_token": 30, "tradingsymbol": "C"}], "manual_refresh")
            self.assertEqual([3], [row["instrument_id"] for row in latest["additions"]])
            self.assertEqual([2], [row["instrument_id"] for row in latest["removals"]])
            self.assertEqual(1, len(latest["mapping_changes"]))
            self.assertEqual("manual_refresh", json.loads((Path(tmp) / "universe.json").read_text())["latest"]["source"])

    def test_conflicting_provider_mapping_is_visible(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            latest = UniverseAudit(Path(tmp) / "universe.json").record([
                {"instrument_id": 1, "source_instrument_token": 10, "tradingsymbol": "DUP"},
                {"instrument_id": 2, "source_instrument_token": 10, "tradingsymbol": "DUP"},
            ], "bootstrap")
            self.assertGreaterEqual(len(latest["conflicts"]), 2)

    def test_unchanged_bootstrap_does_not_create_audit_noise(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            audit = UniverseAudit(Path(tmp) / "universe.json")
            universe = [{"instrument_id": 1, "source_instrument_token": 10, "tradingsymbol": "A"}]
            audit.record(universe, "bootstrap")
            audit.record(universe, "bootstrap")
            self.assertEqual(1, len(audit.load()["history"]))

    def test_failed_partial_refresh_preserves_last_known_good_universe(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            audit = UniverseAudit(Path(tmp) / "universe.json")
            known_good = [{"instrument_id": 1, "source_instrument_token": 10, "tradingsymbol": "A"}]
            audit.record(known_good, "bootstrap")
            latest = audit.record_failure(0, "manual_refresh", "provider returned incomplete universe")
            state = audit.load()
            self.assertFalse(latest["accepted"])
            self.assertEqual(1, len(state["current"]))
            self.assertEqual(1, state["current"][0]["instrument_id"])
            self.assertEqual(10, state["current"][0]["source_instrument_token"])
            self.assertIn("incomplete", latest["warnings"][0])


if __name__ == "__main__":
    unittest.main()
