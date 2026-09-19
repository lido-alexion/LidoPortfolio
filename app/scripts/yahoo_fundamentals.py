#!/usr/bin/env python3
"""Fetch Yahoo fundamentals through the managed yfinance runtime.

The adapter deliberately emits only plain JSON. PHP remains responsible for
StoX normalization, availability semantics, revisions, and persistence.
"""

from __future__ import annotations

import datetime as dt
import json
import math
import sys
from typing import Any, Callable


def json_value(value: Any) -> Any:
    if hasattr(value, "item"):
        value = value.item()
    if value is None:
        return None
    if isinstance(value, float) and math.isnan(value):
        return None
    if isinstance(value, (str, int, float, bool)):
        return value
    return str(value)


def period_date(value: Any) -> str:
    if hasattr(value, "to_pydatetime"):
        value = value.to_pydatetime()
    if isinstance(value, dt.datetime):
        return value.date().isoformat()
    if isinstance(value, dt.date):
        return value.isoformat()
    text = str(value)
    return text[:10]


def frame_rows(frame: Any) -> list[dict[str, Any]]:
    if frame is None or getattr(frame, "empty", True):
        return []

    rows: list[dict[str, Any]] = []
    columns = sorted(list(frame.columns), key=period_date, reverse=True)
    for column in columns:
        facts: dict[str, Any] = {}
        series = frame[column]
        for name, value in series.items():
            facts[str(name)] = json_value(value)
        rows.append({"period_end": period_date(column), "facts": facts})
    return rows


def fetch_statements(symbol: str, cadence: str, ticker_factory: Callable[[str], Any]) -> dict[str, Any]:
    ticker = ticker_factory(symbol)
    prefix = "quarterly_" if cadence == "quarterly" else ""
    frames = {
        "income_statement": getattr(ticker, f"{prefix}financials", None),
        "balance_sheet": getattr(ticker, f"{prefix}balance_sheet", None),
        "cash_flow": getattr(ticker, f"{prefix}cashflow", None),
    }
    statements = {name: frame_rows(frame) for name, frame in frames.items()}
    if not any(statements.values()):
        raise RuntimeError("yfinance returned no fundamental statements")
    return {
        "schema_version": 1,
        "symbol": symbol,
        "cadence": cadence,
        "statements": statements,
    }


def main(argv: list[str] | None = None, ticker_factory: Callable[[str], Any] | None = None) -> int:
    args = sys.argv[1:] if argv is None else argv
    if len(args) != 2 or args[1] not in {"quarterly", "annual"}:
        print("usage: yahoo_fundamentals.py SYMBOL quarterly|annual", file=sys.stderr)
        return 2

    try:
        if ticker_factory is None:
            import yfinance as yf

            ticker_factory = yf.Ticker
        payload = fetch_statements(args[0], args[1], ticker_factory)
        print(json.dumps(payload, ensure_ascii=True, allow_nan=False, separators=(",", ":")))
        return 0
    except Exception as error:
        print(f"yahoo fundamentals adapter failed: {error}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
