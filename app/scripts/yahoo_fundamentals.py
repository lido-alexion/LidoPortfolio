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
import re
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


def candidate_symbols(symbol: str) -> list[str]:
    """Return the primary listing, plus the dual-listed BSE fallback."""
    if symbol.endswith(".NS"):
        return [symbol, f"{symbol[:-3]}.BO"]
    return [symbol]


def identity_info(ticker: Any) -> dict[str, Any]:
    """Read identity through yfinance's normal session, never a direct URL."""
    info = ticker.get_info()
    if not isinstance(info, dict):
        raise RuntimeError("Yahoo identity response was not an object")
    return {
        "symbol": info.get("symbol"),
        "longName": info.get("longName"),
        "shortName": info.get("shortName"),
        "exchange": info.get("exchange"),
        "quoteType": info.get("quoteType"),
    }


def identity_matches(expected_name: str, identity: dict[str, Any]) -> bool:
    """Require a conservative issuer-name overlap before accepting .BO fallback."""
    if not expected_name.strip():
        return False
    ignored = {"limited", "ltd", "india", "inc", "incorporated", "company", "co", "pvt", "private"}
    expected = {token for token in re.findall(r"[a-z0-9]+", expected_name.lower()) if token not in ignored and len(token) >= 3}
    actual_text = " ".join(str(identity.get(key) or "") for key in ("longName", "shortName")).lower()
    actual = {token for token in re.findall(r"[a-z0-9]+", actual_text) if token not in ignored and len(token) >= 3}
    return bool(expected and actual and expected.intersection(actual))


def main(argv: list[str] | None = None, ticker_factory: Callable[[str], Any] | None = None) -> int:
    args = sys.argv[1:] if argv is None else argv
    if len(args) not in {2, 3} or args[1] not in {"quarterly", "annual"}:
        print("usage: yahoo_fundamentals.py SYMBOL quarterly|annual [EXPECTED_ISSUER_NAME]", file=sys.stderr)
        return 2

    try:
        if ticker_factory is None:
            import yfinance as yf

            ticker_factory = yf.Ticker
        requested_symbol = args[0]
        expected_name = args[2] if len(args) == 3 else ""
        last_error: Exception | None = None
        for provider_symbol in candidate_symbols(requested_symbol):
            try:
                ticker = ticker_factory(provider_symbol)
                payload = fetch_statements(provider_symbol, args[1], lambda _: ticker)
                identity = None
                if provider_symbol != requested_symbol:
                    identity = identity_info(ticker)
                    if not identity_matches(expected_name, identity):
                        raise RuntimeError("Yahoo fallback identity did not match the requested issuer")
                # Keep the requested security identity stable for the PHP
                # contract while retaining the symbol that supplied the data.
                payload["symbol"] = requested_symbol
                payload["provider_symbol"] = provider_symbol
                payload["identity"] = identity
                print(json.dumps(payload, ensure_ascii=True, allow_nan=False, separators=(",", ":")))
                return 0
            except RuntimeError as error:
                last_error = error
        if last_error is not None:
            raise last_error
        raise RuntimeError("no Yahoo symbols were available")
    except Exception as error:
        print(f"yahoo fundamentals adapter failed: {error}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
