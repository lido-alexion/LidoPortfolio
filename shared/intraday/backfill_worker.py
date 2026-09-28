#!/usr/bin/env python3
"""
FEAT-065 — resumable Kite historical backfill worker (Mac/research host).

Dry-run by default. When configured, posts checkpoint progress to Laravel:
  POST /api/internal/intraday-backfill/checkpoints
"""

from __future__ import annotations

import argparse
import json
import os
import sys
import urllib.error
import urllib.request
from dataclasses import dataclass
from pathlib import Path
from typing import Any

from instrument_resolver import load_universe_symbols, resolve_instrument_token
from kite_historical_client import KiteHistoricalConfig, fetch_minute_bars_chunked
from parquet_store import write_bars


@dataclass
class WorkerConfig:
    api_base: str
    token: str
    corpus_root: str
    dry_run: bool
    max_attempts: int = 3
    backoff_seconds: float = 1.0


@dataclass
class KiteWorkerCredentials:
    api_key: str
    access_token: str
    instrument_token: int | None


def load_config() -> WorkerConfig:
    return WorkerConfig(
        api_base=os.environ.get("STOXLA_INTRADAY_API_BASE", "http://127.0.0.1:8000/api").rstrip("/"),
        token=os.environ.get("STOXLA_INTRADAY_BACKFILL_INTERNAL_TOKEN", ""),
        corpus_root=os.environ.get("STOXLA_INTRADAY_CORPUS_ROOT", "shared/intraday/corpus"),
        dry_run=os.environ.get("STOXLA_INTRADAY_DRY_RUN", "1") not in ("0", "false", "False"),
        max_attempts=max(1, int(os.environ.get("STOXLA_INTRADAY_MAX_ATTEMPTS", "3"))),
        backoff_seconds=max(0.0, float(os.environ.get("STOXLA_INTRADAY_BACKOFF_SECONDS", "1"))),
    )


def post_checkpoint(cfg: WorkerConfig, payload: dict[str, Any]) -> dict[str, Any]:
    if cfg.dry_run:
        return {"dry_run": True, "payload": payload}
    if not cfg.token:
        raise RuntimeError("STOXLA_INTRADAY_BACKFILL_INTERNAL_TOKEN is required when dry_run is disabled.")

    url = f"{cfg.api_base}/internal/intraday-backfill/checkpoints"
    body = json.dumps(payload).encode("utf-8")
    request = urllib.request.Request(
        url,
        data=body,
        headers={
            "Content-Type": "application/json",
            "X-Intraday-Backfill-Token": cfg.token,
        },
        method="POST",
    )
    with urllib.request.urlopen(request, timeout=60) as response:
        return json.loads(response.read().decode("utf-8"))


def load_kite_credentials(instrument_token_cli: int | None = None, symbol: str | None = None) -> KiteWorkerCredentials:
    env_token = os.environ.get("STOXLA_KITE_INSTRUMENT_TOKEN")
    token = instrument_token_cli
    if token is None and env_token:
        token = int(env_token)
    if token is None and symbol:
        token = resolve_instrument_token(symbol)
    return KiteWorkerCredentials(
        api_key=os.environ.get("STOXLA_KITE_API_KEY", ""),
        access_token=os.environ.get("STOXLA_KITE_ACCESS_TOKEN", ""),
        instrument_token=token,
    )


def resolve_bars_for_window(
    window_start: str,
    window_end: str,
    cfg: WorkerConfig,
    kite: KiteWorkerCredentials,
    bars: list[dict[str, Any]] | None,
) -> list[dict[str, Any]]:
    if bars is not None:
        return bars
    if cfg.dry_run:
        return []
    if kite.instrument_token and kite.api_key and kite.access_token:
        return fetch_minute_bars_chunked(
            KiteHistoricalConfig(kite.api_key, kite.access_token, kite.instrument_token),
            window_start,
            window_end,
            max_attempts=cfg.max_attempts,
            backoff_seconds=cfg.backoff_seconds,
        )
    return []


def load_bars_from_json(path: str) -> list[dict[str, Any]]:
    data = json.loads(Path(path).read_text(encoding="utf-8"))
    if not isinstance(data, list):
        raise ValueError("--bars-json must contain a JSON array of bar objects.")
    return data


def run_once(
    symbol: str,
    window_start: str,
    window_end: str,
    cfg: WorkerConfig,
    bars: list[dict[str, Any]] | None = None,
    kite: KiteWorkerCredentials | None = None,
) -> dict[str, Any]:
    bars_written = 0
    failure: dict[str, str] | None = None
    try:
        if not cfg.dry_run:
            kite = kite or load_kite_credentials()
            bars = resolve_bars_for_window(window_start, window_end, cfg, kite, bars)
            if bars:
                bars_written = write_bars(cfg.corpus_root, symbol, "NSE", bars)
    except Exception as exc:  # noqa: BLE001
        failure = {"type": type(exc).__name__, "message": str(exc)}

    payload = {
        "symbol": symbol.upper(),
        "exchange": "NSE",
        "window_start": window_start,
        "window_end": window_end,
        "status": "failed" if failure is not None else ("complete" if (not cfg.dry_run and bars_written > 0) else "pending"),
        "bars_written": bars_written,
    }
    if failure is not None:
        payload["last_error"] = failure
    return post_checkpoint(cfg, payload)


def run_universe(
    symbols: list[str],
    window_start: str,
    window_end: str,
    cfg: WorkerConfig,
) -> list[dict[str, Any]]:
    """Process one bounded window for the pinned current-universe snapshot."""
    results = []
    for symbol in sorted({item.upper() for item in symbols if item.strip()}):
        results.append(run_once(
            symbol,
            window_start,
            window_end,
            cfg,
            kite=load_kite_credentials(symbol=symbol),
        ))
    return results


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="StoX intraday historical backfill worker")
    parser.add_argument("--symbol", help="One NSE symbol to backfill")
    parser.add_argument(
        "--universe-map",
        help="JSON SYMBOL -> Kite token map for the pinned current NIFTY 500 universe",
    )
    parser.add_argument("--window-start", required=True)
    parser.add_argument("--window-end", required=True)
    parser.add_argument("--apply", action="store_true", help="Disable dry-run (requires API token)")
    parser.add_argument(
        "--bars-json",
        help="Optional JSON file with 1m OHLCV bars (ts/open/high/low/close/volume) for apply-mode Parquet writes",
    )
    parser.add_argument(
        "--instrument-token",
        type=int,
        help="Kite instrument token (defaults to STOXLA_KITE_INSTRUMENT_TOKEN)",
    )
    args = parser.parse_args(argv)

    cfg = load_config()
    if args.apply:
        cfg.dry_run = False

    if not args.symbol and not args.universe_map:
        parser.error("one of --symbol or --universe-map is required")
    if args.symbol and args.universe_map:
        parser.error("--symbol and --universe-map cannot be combined")
    if args.instrument_token and args.universe_map:
        parser.error("--instrument-token cannot be combined with --universe-map")

    bars = load_bars_from_json(args.bars_json) if args.bars_json else None

    try:
        if args.universe_map:
            symbols = load_universe_symbols(args.universe_map)
            if not symbols:
                raise ValueError("The current-universe map contains no symbols.")
            if bars is not None:
                raise ValueError("--bars-json is only supported with --symbol.")
            result = {
                "universe": "nifty500",
                "universe_source": "explicit_instrument_map",
                "symbol_count": len(symbols),
                "results": run_universe(symbols, args.window_start, args.window_end, cfg),
            }
        else:
            result = run_once(
                args.symbol,
                args.window_start,
                args.window_end,
                cfg,
                bars=bars,
                kite=load_kite_credentials(args.instrument_token, symbol=args.symbol),
            )
    except urllib.error.HTTPError as exc:
        print(exc.read().decode("utf-8", errors="replace"), file=sys.stderr)
        return 1
    except Exception as exc:  # noqa: BLE001
        print(str(exc), file=sys.stderr)
        return 1

    print(json.dumps(result, indent=2))
    if result.get("status") == "failed" or any(
        isinstance(item, dict) and item.get("status") == "failed"
        for item in result.get("results", [])
    ):
        return 2
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
