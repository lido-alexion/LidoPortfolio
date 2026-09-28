import json
import os
import sys
import tempfile
import unittest
from datetime import datetime, timezone
from pathlib import Path
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from collector.collector_app import CollectorApp  # noqa: E402


class CollectorFinalizationTest(unittest.TestCase):
    def test_finalization_drains_active_minute_and_writes_backup(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp) / "data"
            backup = Path(tmp) / "backup"
            with patch.dict(os.environ, {"MICROSTRUCTURE_BACKUP_ROOT": str(backup)}):
                app = CollectorApp(Path(tmp) / "command.json", Path(tmp) / "heartbeat.json", root)
                meta = {"instrument_id": 1, "source_instrument_token": 99, "exchange": "NSE", "tradingsymbol": "TEST"}
                app.aggregator.ingest(
                    meta,
                    {"last_price": 100.0, "volume_traded": 100},
                    at=datetime.now(timezone.utc),
                )
                app.finalize_today(force=True)

            manifest = next(root.rglob("_FINALIZED.json"))
            self.assertEqual(1, json.loads(manifest.read_text(encoding="utf-8"))["row_count"])
            self.assertTrue((backup / manifest.parent.relative_to(root) / "_FINALIZED.json").is_file())

    def test_backup_failure_preserves_canonical_partition_and_is_retryable(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp) / "data"
            backup = Path(tmp) / "backup"
            with patch.dict(os.environ, {"MICROSTRUCTURE_BACKUP_ROOT": str(backup)}):
                app = CollectorApp(Path(tmp) / "command.json", Path(tmp) / "heartbeat.json", root)
                app.aggregator.ingest(
                    {"instrument_id": 1, "source_instrument_token": 99, "exchange": "NSE", "tradingsymbol": "TEST"},
                    {"last_price": 100.0},
                    at=datetime.now(timezone.utc),
                )
                with patch("collector.collector_app.shutil.copytree", side_effect=OSError("destination unavailable")):
                    app.finalize_today(force=True)
                self.assertTrue(next(root.rglob("_FINALIZED.json")).is_file())
                self.assertEqual("backup_failed", app._finalization.load()["status"])
                self.assertTrue(app._finalization.retry_allowed())

    def test_repeated_finalization_does_not_append_duplicate_rows(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp) / "data"
            with patch.dict(os.environ, {"MICROSTRUCTURE_BACKUP_ROOT": str(Path(tmp) / "backup")}):
                app = CollectorApp(Path(tmp) / "command.json", Path(tmp) / "heartbeat.json", root)
                app.aggregator.ingest(
                    {"instrument_id": 1, "source_instrument_token": 99, "exchange": "NSE", "tradingsymbol": "TEST"},
                    {"last_price": 100.0},
                    at=datetime.now(timezone.utc),
                )
                app.finalize_today(force=True)
                first = json.loads(next(root.rglob("_FINALIZED.json")).read_text())["row_count"]
                app.finalize_today(force=True)
                second = json.loads(next(root.rglob("_FINALIZED.json")).read_text())["row_count"]
                self.assertEqual(first, second)


if __name__ == "__main__":
    unittest.main()
