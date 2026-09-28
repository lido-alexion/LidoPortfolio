"""Bounded audit trail for NIFTY 500 universe and provider-mapping changes."""

from __future__ import annotations

import json
import os
from datetime import datetime, timezone
from pathlib import Path
from typing import Any


class UniverseAudit:
    def __init__(self, path: Path, max_records: int = 100) -> None:
        self.path = path
        self.max_records = max(1, max_records)

    def load(self) -> dict[str, Any]:
        if not self.path.is_file():
            return {"current": [], "history": []}
        try:
            payload = json.loads(self.path.read_text(encoding="utf-8"))
        except (OSError, json.JSONDecodeError):
            return {"current": [], "history": [], "warning": "universe audit state unreadable"}
        return payload if isinstance(payload, dict) else {"current": [], "history": []}

    def record(self, universe: list[dict[str, Any]], source: str) -> dict[str, Any]:
        state = self.load()
        previous = {str(row.get("instrument_id")): row for row in state.get("current", []) if row.get("instrument_id") is not None}
        current = {str(row.get("instrument_id")): _normalise(row) for row in universe if row.get("instrument_id") is not None}
        additions = [current[key] for key in sorted(set(current) - set(previous))]
        removals = [previous[key] for key in sorted(set(previous) - set(current))]
        mapping_changes = []
        for key in sorted(set(previous) & set(current)):
            before, after = previous[key], current[key]
            changed = {field: {"before": before.get(field), "after": after.get(field)} for field in ("source_instrument_token", "exchange", "tradingsymbol", "symbol") if before.get(field) != after.get(field)}
            if changed:
                mapping_changes.append({"instrument_id": after["instrument_id"], "changes": changed})

        conflicts = _conflicts(universe)
        if previous == current and not conflicts and source == "bootstrap" and isinstance(state.get("latest"), dict):
            return state["latest"]
        record = {
            "at": datetime.now(timezone.utc).isoformat(),
            "source": source,
            "old_count": len(previous),
            "new_count": len(current),
            "additions": additions,
            "removals": removals,
            "mapping_changes": mapping_changes,
            "conflicts": conflicts,
            "warnings": ["universe contains mapping conflicts"] if conflicts else [],
        }
        history = list(state.get("history", []))
        history.append(record)
        payload = {"current": list(current.values()), "history": history[-self.max_records:], "latest": record}
        self._save(payload)
        return record

    def _save(self, payload: dict[str, Any]) -> None:
        self.path.parent.mkdir(parents=True, exist_ok=True)
        staging = self.path.with_name(f".{self.path.name}.staging")
        staging.write_text(json.dumps(payload, indent=2, sort_keys=True), encoding="utf-8")
        os.replace(staging, self.path)


def _normalise(row: dict[str, Any]) -> dict[str, Any]:
    return {field: row.get(field) for field in ("instrument_id", "source_instrument_token", "exchange", "tradingsymbol", "symbol")}


def _conflicts(universe: list[dict[str, Any]]) -> list[dict[str, Any]]:
    conflicts = []
    for field in ("instrument_id", "source_instrument_token", "tradingsymbol"):
        seen: dict[str, Any] = {}
        for row in universe:
            value = row.get(field)
            if value in (None, ""):
                continue
            key = str(value).upper() if isinstance(value, str) else str(value)
            if key in seen and seen[key] != row.get("instrument_id"):
                conflicts.append({"field": field, "value": value, "instrument_ids": [seen[key], row.get("instrument_id")]})
            else:
                seen[key] = row.get("instrument_id")
    return conflicts
