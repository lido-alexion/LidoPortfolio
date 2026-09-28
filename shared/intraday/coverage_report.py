"""Corpus coverage inventory for FEAT-065 checkpoints and operator validation."""

from __future__ import annotations

from collections import defaultdict
from dataclasses import dataclass
from pathlib import Path
from typing import Any

from parquet_store import SCHEMA_VERSION, partition_dir

try:
    import pyarrow.parquet as pq
except ImportError:  # pragma: no cover
    pq = None


@dataclass
class SymbolCoverage:
    symbol: str
    exchange: str
    bar_count: int
    first_ts: str | None
    last_ts: str | None


def scan_corpus(corpus_root: str | Path) -> dict[str, Any]:
    root = Path(corpus_root)
    if not root.is_dir():
        return {
            "corpus_root": str(root),
            "schema_version": SCHEMA_VERSION,
            "parquet_files": 0,
            "symbols": [],
            "totals": {"bars": 0},
        }

    aggregates: dict[tuple[str, str], dict[str, Any]] = defaultdict(
        lambda: {"bar_count": 0, "first_ts": None, "last_ts": None}
    )
    parquet_files = 0

    for path in root.rglob("*.parquet"):
        if SCHEMA_VERSION not in path.parts:
            continue
        parquet_files += 1
        if pq is None:
            continue
        table = pq.read_table(path, columns=["symbol", "exchange", "ts"])
        rows = table.to_pylist()
        for row in rows:
            key = (str(row.get("symbol") or "").upper(), str(row.get("exchange") or "NSE").upper())
            bucket = aggregates[key]
            bucket["bar_count"] += 1
            ts = str(row.get("ts") or "")
            if not ts:
                continue
            if bucket["first_ts"] is None or ts < bucket["first_ts"]:
                bucket["first_ts"] = ts
            if bucket["last_ts"] is None or ts > bucket["last_ts"]:
                bucket["last_ts"] = ts

    symbols = [
        {
            "symbol": key[0],
            "exchange": key[1],
            "bar_count": value["bar_count"],
            "first_ts": value["first_ts"],
            "last_ts": value["last_ts"],
        }
        for key, value in sorted(aggregates.items())
    ]
    total_bars = sum(item["bar_count"] for item in symbols)

    return {
        "corpus_root": str(root),
        "schema_version": SCHEMA_VERSION,
        "parquet_files": parquet_files,
        "symbols": symbols,
        "totals": {"bars": total_bars},
    }


def partition_paths_for_month(corpus_root: str | Path, year: int, month: int) -> list[Path]:
    from datetime import date

    return list(partition_dir(Path(corpus_root), date(year, month, 1)).glob("*.parquet"))


def main(argv: list[str] | None = None) -> int:
    import argparse
    import json

    parser = argparse.ArgumentParser(description="Scan FEAT-065 intraday Parquet corpus coverage")
    parser.add_argument(
        "--corpus-root",
        default="shared/intraday/corpus",
        help="Corpus root (STOXLA_INTRADAY_CORPUS_ROOT)",
    )
    args = parser.parse_args(argv)
    print(json.dumps(scan_corpus(args.corpus_root), indent=2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
