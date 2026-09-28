"""Deduplicated daily coverage accounting for collector heartbeat/validation."""

from __future__ import annotations

from collections import Counter
from typing import Any


class CoverageAccumulator:
    def __init__(self) -> None:
        self._seen: set[tuple[str, str]] = set()
        self._counts: Counter[str] = Counter()

    def record(self, rows: list[dict[str, Any]]) -> None:
        for row in rows:
            key = (str(row.get("instrument_id")), str(row.get("minute_timestamp")))
            if key in self._seen:
                continue
            self._seen.add(key)
            self._counts[str(row.get("coverage_class") or "unknown")] += 1

    def reset(self) -> None:
        self._seen.clear()
        self._counts.clear()

    def counts(self) -> dict[str, int]:
        return dict(self._counts)

    def summary(self, expected_minutes: int, instrument_count: int) -> dict[str, Any]:
        observed = sum(self._counts.values())
        expected = expected_minutes * max(1, instrument_count)
        return {
            "expected_minute_count": expected_minutes,
            "observed_row_count": observed,
            "quality_counts": self.counts(),
            "expected_instrument_minutes": expected,
            "coverage_percent": round((observed / expected) * 100, 2) if expected else None,
        }
