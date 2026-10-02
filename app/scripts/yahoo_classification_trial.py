#!/usr/bin/env python3
"""Bounded Yahoo classification validation using the maintained yfinance client.

This is deliberately separate from fundamentals ingestion and production
classification observations.  It calls Ticker.get_info(), allowing yfinance
to manage its ordinary Yahoo session/cookie/crumb flow.  It never calls a
Yahoo endpoint directly and never writes StoX production tables.
"""

from __future__ import annotations

import argparse
import datetime as dt
import hashlib
import json
import sys
import time
from pathlib import Path
from typing import Any, Callable


MAX_SYMBOLS = 7
DEFAULT_RETRIES = 2


def json_safe(value: Any) -> Any:
    if value is None or isinstance(value, (str, int, float, bool)):
        return value
    if isinstance(value, dict):
        return {str(key): json_safe(item) for key, item in value.items()}
    if isinstance(value, (list, tuple)):
        return [json_safe(item) for item in value]
    return str(value)


def canonical_hash(payload: dict[str, Any]) -> str:
    encoded = json.dumps(payload, sort_keys=True, ensure_ascii=False, separators=(",", ":"), default=str).encode()
    return hashlib.sha256(encoded).hexdigest()


def classification_response(symbol: str, info: dict[str, Any], observed_at: str) -> dict[str, Any]:
    safe = json_safe(info)
    if not isinstance(safe, dict):
        raise RuntimeError("Yahoo classification response was not an object")

    identity = {key: safe.get(key) for key in ("symbol", "shortName", "longName", "exchange", "quoteType", "currency")}
    labels = {"sector": safe.get("sector"), "industry": safe.get("industry")}
    return {
        "status": "success",
        "requested_symbol": symbol,
        "provider_symbol": safe.get("symbol") or symbol,
        "identity": identity,
        "raw_labels": labels,
        "missing_fields": [key for key, value in labels.items() if not value],
        "raw_payload_sha256": canonical_hash(safe),
        "raw_response": safe,
        "observed_at": observed_at,
        "mapping_status": "pending_nse_taxonomy_gate",
    }


def fetch_one(
    symbol: str,
    ticker_factory: Callable[[str], Any],
    retries: int,
    sleep: Callable[[float], None] = time.sleep,
) -> dict[str, Any]:
    attempts = 0
    last_error: Exception | None = None
    while attempts <= retries:
        attempts += 1
        try:
            # Do not replace this with direct query1/query2 calls.  get_info()
            # exercises yfinance's normal session and crumb handling.
            info = ticker_factory(symbol).get_info()
            observed_at = dt.datetime.now(dt.timezone.utc).isoformat()
            result = classification_response(symbol, info, observed_at)
            result["attempts"] = attempts
            return result
        except Exception as error:  # provider errors are evidence, not observations
            last_error = error
            if attempts <= retries:
                sleep(min(2 ** (attempts - 1), 4))

    return {
        "status": "failed",
        "requested_symbol": symbol,
        "attempts": attempts,
        "error_type": type(last_error).__name__ if last_error else "UnknownError",
        "error": str(last_error)[:500] if last_error else "unknown Yahoo classification failure",
        "observed_at": dt.datetime.now(dt.timezone.utc).isoformat(),
        "mapping_status": "unknown",
    }


def run_trial(
    symbols: list[str],
    cache_path: Path,
    retries: int = DEFAULT_RETRIES,
    ticker_factory: Callable[[str], Any] | None = None,
    sleep: Callable[[float], None] = time.sleep,
) -> dict[str, Any]:
    if not symbols or len(symbols) > MAX_SYMBOLS:
        raise ValueError(f"trial requires 1-{MAX_SYMBOLS} symbols")
    if retries < 0 or retries > DEFAULT_RETRIES:
        raise ValueError(f"retries must be between 0 and {DEFAULT_RETRIES}")

    if ticker_factory is None:
        import yfinance as yf

        ticker_factory = yf.Ticker

    cache: dict[str, Any] = {}
    if cache_path.exists():
        loaded = json.loads(cache_path.read_text())
        if isinstance(loaded, dict):
            cache = loaded

    results: list[dict[str, Any]] = []
    for symbol in symbols:
        symbol = symbol.strip().upper()
        cached = cache.get(symbol)
        if isinstance(cached, dict) and cached.get("status") == "success":
            result = dict(cached)
            result["cache_hit"] = True
        else:
            result = fetch_one(symbol, ticker_factory, retries, sleep)
            result["cache_hit"] = False
            cache[symbol] = result
            cache_path.parent.mkdir(parents=True, exist_ok=True)
            cache_path.write_text(json.dumps(cache, ensure_ascii=False, sort_keys=True, indent=2) + "\n")
        results.append(result)

    return {
        "schema_version": 1,
        "trial": "yahoo_classification_validation",
        "provider": "yahoo",
        "client": "yfinance",
        "production_observations_written": False,
        "manual_overrides_changed": False,
        "results": results,
    }


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--symbols", required=True, help="comma-separated Yahoo symbols, capped at seven")
    parser.add_argument("--cache", required=True, type=Path)
    parser.add_argument("--retries", type=int, default=DEFAULT_RETRIES)
    args = parser.parse_args(argv)
    try:
        symbols = [item for item in args.symbols.split(",") if item.strip()]
        print(json.dumps(run_trial(symbols, args.cache, args.retries), ensure_ascii=False, separators=(",", ":")))
        return 0
    except Exception as error:
        print(f"yahoo classification trial failed: {error}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
