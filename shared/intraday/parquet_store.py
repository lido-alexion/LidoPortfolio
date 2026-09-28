"""Schema-versioned Parquet writer for FEAT-065 1-minute OHLCV corpus."""

from __future__ import annotations

from collections import defaultdict
from datetime import date, datetime
from pathlib import Path
from typing import Any

try:
    import pyarrow as pa
    import pyarrow.parquet as pq
except ImportError:  # pragma: no cover - optional until venv installed
    pa = None
    pq = None

SCHEMA_VERSION = "schema_v1"


def partition_dir(corpus_root: Path, trading_day: date) -> Path:
    return (
        Path(corpus_root)
        / SCHEMA_VERSION
        / f"year={trading_day.year:04d}"
        / f"month={trading_day.month:02d}"
    )


def _bar_trading_day(bar: dict[str, Any]) -> date:
    raw = bar.get("ts") or bar.get("timestamp") or bar.get("bar_time")
    if raw is None:
        raise ValueError("Each bar requires ts, timestamp, or bar_time.")
    if isinstance(raw, datetime):
        return raw.date()
    if isinstance(raw, date):
        return raw
    text = str(raw).strip()
    if "T" in text:
        text = text.split("T", 1)[0]
    return date.fromisoformat(text[:10])


def _normalize_row(bar: dict[str, Any], symbol: str, exchange: str) -> dict[str, Any]:
    ts = bar.get("ts") or bar.get("timestamp") or bar.get("bar_time")
    return {
        "ts": str(ts),
        "symbol": str(bar.get("symbol") or symbol).upper(),
        "exchange": str(bar.get("exchange") or exchange).upper(),
        "open": float(bar["open"]),
        "high": float(bar["high"]),
        "low": float(bar["low"]),
        "close": float(bar["close"]),
        "volume": int(bar.get("volume") or 0),
    }


def write_bars(corpus_root: str | Path, symbol: str, exchange: str, bars: list[dict[str, Any]]) -> int:
    """Upsert minute bars into year/month partitions without duplicate timestamps."""
    if not bars:
        return 0
    if pa is None or pq is None:
        raise RuntimeError("pyarrow is required for Parquet writes")

    grouped: dict[date, list[dict[str, Any]]] = defaultdict(list)
    for bar in bars:
        day = _bar_trading_day(bar)
        grouped[day].append(_normalize_row(bar, symbol, exchange))

    written = 0
    for trading_day, rows in sorted(grouped.items()):
        target_dir = partition_dir(Path(corpus_root), trading_day)
        target_dir.mkdir(parents=True, exist_ok=True)
        part_name = f"part-{exchange.upper()}-{symbol.upper()}-{trading_day.isoformat()}.parquet"
        staging_path = target_dir / f".{part_name}.staging"
        final_path = target_dir / part_name
        merged = rows
        if final_path.exists():
            existing = pq.read_table(final_path).to_pylist()
            by_timestamp = {str(row['ts']): row for row in existing}
            by_timestamp.update({str(row['ts']): row for row in rows})
            merged = [by_timestamp[key] for key in sorted(by_timestamp)]
        table = pa.Table.from_pylist(merged)
        pq.write_table(table, staging_path, compression="zstd")
        staging_path.replace(final_path)
        written += len(merged)

    return written
