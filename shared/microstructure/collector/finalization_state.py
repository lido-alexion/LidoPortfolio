"""Durable, atomic state for the collector's finalization/backup workflow."""

from __future__ import annotations

import json
import os
from datetime import datetime, timezone
from pathlib import Path
from typing import Any


class FinalizationState:
    """Small JSON state store that survives collector process restarts."""

    def __init__(self, path: Path, max_retries: int = 3) -> None:
        self.path = path
        self.max_retries = max(1, max_retries)

    def load(self) -> dict[str, Any]:
        if not self.path.is_file():
            return {}
        try:
            payload = json.loads(self.path.read_text(encoding="utf-8"))
        except (OSError, json.JSONDecodeError):
            return {"status": "state_corrupt"}
        return payload if isinstance(payload, dict) else {"status": "state_corrupt"}

    def save(self, payload: dict[str, Any]) -> None:
        self.path.parent.mkdir(parents=True, exist_ok=True)
        staging = self.path.with_name(f".{self.path.name}.staging")
        staging.write_text(json.dumps(payload, indent=2, sort_keys=True), encoding="utf-8")
        os.replace(staging, self.path)

    def mark_finalization_started(self, trading_day: str) -> dict[str, Any]:
        current = self.load()
        attempts = int(current.get("finalization_attempts", 0)) + 1
        payload = {
            **current,
            "trading_day": trading_day,
            "status": "finalizing",
            "finalization_attempts": attempts,
            "finalization_started_at": _now(),
            "last_error": None,
        }
        self.save(payload)
        return payload

    def mark_finalization_failed(self, error: str) -> dict[str, Any]:
        payload = {
            **self.load(),
            "status": "finalization_failed",
            "last_error": error,
            "finalization_failed_at": _now(),
        }
        self.save(payload)
        return payload

    def mark_finalized(self, trading_day: str, row_count: int) -> dict[str, Any]:
        payload = {
            **self.load(),
            "trading_day": trading_day,
            "status": "finalized",
            "row_count": row_count,
            "finalized_at": _now(),
            "last_error": None,
        }
        self.save(payload)
        return payload

    def mark_backup(self, status: str, error: str | None = None) -> dict[str, Any]:
        current = self.load()
        payload = {
            **current,
            "status": "backup_failed" if status == "backup_failed" else ("finalized" if status.startswith("ok:") else current.get("status", "finalized")),
            "backup_status": status,
            "backup_at": _now(),
            "backup_error": error,
        }
        self.save(payload)
        return payload

    def retry_allowed(self, now: datetime | None = None) -> bool:
        state = self.load()
        if state.get("status") not in {"finalization_failed", "backup_failed"}:
            return False
        if int(state.get("finalization_attempts", 0)) >= self.max_retries:
            return False
        failed_at = _parse_datetime(state.get("finalization_failed_at"))
        if failed_at is None:
            return True
        current = now or datetime.now(timezone.utc)
        return (current - failed_at).total_seconds() >= min(300, 30 * 2 ** max(0, int(state.get("finalization_attempts", 1)) - 1))


def _now() -> str:
    return datetime.now(timezone.utc).isoformat()


def _parse_datetime(value: Any) -> datetime | None:
    if not isinstance(value, str):
        return None
    try:
        parsed = datetime.fromisoformat(value)
    except ValueError:
        return None
    return parsed if parsed.tzinfo else parsed.replace(tzinfo=timezone.utc)
