import json
import os
import sys
import tempfile
import time
import unittest
from datetime import datetime, timezone
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from collector.raw_tick_spool import RawTickSpool  # noqa: E402


class RawTickSpoolTest(unittest.TestCase):
    def test_prune_day_removes_only_finalized_day(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            spool = RawTickSpool(Path(tmp) / "spool", max_bytes=1024 * 1024, max_age_seconds=3600)
            meta = {"instrument_id": 1, "source_instrument_token": 9}
            spool.append(meta, {"last_price": 100}, at=datetime(2026, 9, 27, tzinfo=timezone.utc))
            spool.append(meta, {"last_price": 101}, at=datetime(2026, 9, 28, tzinfo=timezone.utc))
            spool.prune_day(datetime(2026, 9, 27, tzinfo=timezone.utc).date())
            self.assertFalse((spool.root / "2026-09-27").exists())
            self.assertTrue((spool.root / "2026-09-28").exists())

    def test_enforces_size_bound(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp) / "spool"
            spool = RawTickSpool(root, max_bytes=800, max_age_seconds=3600)
            meta = {"instrument_id": 1, "source_instrument_token": 9}
            tick = {"last_price": 100.0}
            at = datetime(2026, 9, 27, 10, 0, 0, tzinfo=timezone.utc)
            for i in range(30):
                spool.append(meta, {**tick, "seq": i}, at=at)
            stats = spool.stats()
            self.assertLessEqual(stats["total_bytes"], 800)
            self.assertGreater(stats["file_count"], 0)

    def test_prunes_stale_files(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp) / "spool"
            spool = RawTickSpool(root, max_bytes=1024 * 1024, max_age_seconds=1)
            day_dir = root / "2026-09-27"
            day_dir.mkdir(parents=True)
            stale = day_dir / "old.jsonl"
            stale.write_text(json.dumps({"tick": 1}) + "\n", encoding="utf-8")
            old = time.time() - 120
            os.utime(stale, (old, old))
            spool.enforce_bounds()
            self.assertFalse(stale.exists())


if __name__ == "__main__":
    unittest.main()
