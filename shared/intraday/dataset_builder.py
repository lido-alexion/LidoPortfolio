"""DuckDB / Polars access over the canonical intraday Parquet corpus (FEAT-065 → FEAT-057 handoff)."""

from __future__ import annotations

from pathlib import Path
from typing import Any

from parquet_store import SCHEMA_VERSION

try:
    import duckdb
except ImportError:  # pragma: no cover
    duckdb = None

try:
    import polars as pl
except ImportError:  # pragma: no cover
    pl = None


def parquet_glob(corpus_root: str | Path) -> str:
    root = Path(corpus_root)
    return str(root / SCHEMA_VERSION / "**" / "*.parquet")


def duckdb_symbol_summary(corpus_root: str | Path, symbol: str | None = None) -> list[dict[str, Any]]:
    if duckdb is None:
        raise RuntimeError("duckdb is required for SQL scans over the corpus.")

    glob = parquet_glob(corpus_root).replace("'", "''")
    where = ""
    if symbol:
        safe_symbol = symbol.upper().replace("'", "")
        where = f"WHERE upper(symbol) = '{safe_symbol}'"

    query = f"""
        SELECT
            upper(symbol) AS symbol,
            upper(exchange) AS exchange,
            min(ts) AS first_ts,
            max(ts) AS last_ts,
            count(*) AS bar_count
        FROM read_parquet('{glob}', hive_partitioning=false)
        {where}
        GROUP BY 1, 2
        ORDER BY 1
    """
    con = duckdb.connect()
    rows = con.execute(query).fetchall()
    columns = ["symbol", "exchange", "first_ts", "last_ts", "bar_count"]
    return [dict(zip(columns, row, strict=True)) for row in rows]


def polars_daily_ohlc(
    corpus_root: str | Path,
    symbol: str,
    through_date: str,
) -> Any:
    """PIT-safe daily OHLCV aggregated from 1m bars with ts <= through_date 15:30."""
    if pl is None:
        raise RuntimeError("polars is required for dataframe dataset assembly.")

    glob = parquet_glob(corpus_root)
    frame = (
        pl.scan_parquet(glob, hive_partitioning=False)
        .filter(pl.col("symbol").str.to_uppercase() == symbol.upper())
        .filter(pl.col("ts") <= f"{through_date} 15:30:00")
        .with_columns(pl.col("ts").str.slice(0, 10).alias("session_date"))
        .group_by("session_date")
        .agg(
            pl.col("open").first().alias("open"),
            pl.col("high").max().alias("high"),
            pl.col("low").min().alias("low"),
            pl.col("close").last().alias("close"),
            pl.col("volume").sum().alias("volume"),
        )
        .sort("session_date")
        .collect()
    )
    return frame
