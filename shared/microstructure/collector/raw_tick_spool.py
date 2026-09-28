"""Bounded crash-recovery raw tick spool (not a permanent dataset)."""

from __future__ import annotations

import json
import os
from datetime import date, datetime, timezone
from pathlib import Path
from typing import Any


class RawTickSpool:
    def __init__(self, root: Path, max_bytes: int, max_age_seconds: int) -> None:
        self.root = root
        self.max_bytes = max(1, max_bytes)
        self.max_age_seconds = max(60, max_age_seconds)
        self.root.mkdir(parents=True, exist_ok=True)

    @classmethod
    def from_env(cls, data_root: Path) -> RawTickSpool:
        max_mb = int(os.environ.get("MICROSTRUCTURE_RAW_SPOOL_MAX_MB", "512"))
        max_age_minutes = int(os.environ.get("MICROSTRUCTURE_RAW_SPOOL_MAX_AGE_MINUTES", "60"))
        spool_root = data_root / "raw_tick_spool"
        return cls(spool_root, max_bytes=max_mb * 1024 * 1024, max_age_seconds=max_age_minutes * 60)

    def append(self, meta: dict[str, Any], tick: dict[str, Any], at: datetime | None = None) -> None:
        now = at or datetime.now(timezone.utc)
        trading_day = now.date()
        day_dir = self.root / trading_day.isoformat()
        day_dir.mkdir(parents=True, exist_ok=True)
        path = day_dir / f"spool-{now.strftime('%H')}.jsonl"
        payload = {
            "received_at": now.isoformat(),
            "instrument_id": meta.get("instrument_id"),
            "source_instrument_token": meta.get("source_instrument_token"),
            "tick": tick,
        }
        with path.open("a", encoding="utf-8") as handle:
            handle.write(json.dumps(payload, separators=(",", ":"), default=str))
            handle.write("\n")
        self.enforce_bounds()

    def enforce_bounds(self) -> None:
        files = sorted(self.root.rglob("*.jsonl"), key=lambda p: p.stat().st_mtime)
        now_ts = datetime.now(timezone.utc).timestamp()
        for path in files:
            age = now_ts - path.stat().st_mtime
            if age > self.max_age_seconds:
                path.unlink(missing_ok=True)
        files = sorted(self.root.rglob("*.jsonl"), key=lambda p: p.stat().st_mtime)
        total = sum(p.stat().st_size for p in files)
        while files and total > self.max_bytes:
            victim = files.pop(0)
            total -= victim.stat().st_size
            victim.unlink(missing_ok=True)
        self._prune_empty_day_dirs()

    def stats(self) -> dict[str, Any]:
        files = list(self.root.rglob("*.jsonl"))
        total_bytes = sum(p.stat().st_size for p in files)
        return {
            "file_count": len(files),
            "total_bytes": total_bytes,
            "max_bytes": self.max_bytes,
            "max_age_seconds": self.max_age_seconds,
        }

    def prune_day(self, trading_day: date) -> None:
        """Remove recovery ticks only after the day's canonical data is finalized."""
        day_dir = self.root / trading_day.isoformat()
        if not day_dir.is_dir():
            return
        for path in day_dir.glob("*.jsonl"):
            path.unlink(missing_ok=True)
        self._prune_empty_day_dirs()

    def _prune_empty_day_dirs(self) -> None:
        for day_dir in sorted(self.root.iterdir()):
            if not day_dir.is_dir():
                continue
            if not any(day_dir.iterdir()):
                day_dir.rmdir()
