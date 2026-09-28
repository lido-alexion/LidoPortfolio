"""Kite Connect historical 1-minute OHLCV fetch (FEAT-065). Normalizes to canonical bar dicts."""

from __future__ import annotations

import json
import urllib.error
import urllib.parse
import urllib.request
import time
from dataclasses import dataclass
from datetime import date, datetime, timedelta
from typing import Any, Callable

KITE_API_BASE = "https://api.kite.trade"


@dataclass
class KiteHistoricalConfig:
    api_key: str
    access_token: str
    instrument_token: int


HttpGet = Callable[[str, dict[str, str]], dict[str, Any]]
Sleep = Callable[[float], None]


def _retryable_fetch_error(error: BaseException) -> bool:
    if isinstance(error, urllib.error.HTTPError):
        return error.code == 429 or 500 <= error.code <= 599
    return isinstance(error, (urllib.error.URLError, TimeoutError, ConnectionError))


def default_http_get(url: str, headers: dict[str, str]) -> dict[str, Any]:
    request = urllib.request.Request(url, headers=headers, method="GET")
    with urllib.request.urlopen(request, timeout=120) as response:
        payload = json.loads(response.read().decode("utf-8"))
    if payload.get("status") != "success":
        message = payload.get("message") or "Kite historical request failed."
        raise RuntimeError(message)
    return payload


def normalize_kite_candle(candle: list[Any]) -> dict[str, Any]:
    if len(candle) < 6:
        raise ValueError("Kite candle must include timestamp and OHLCV.")
    ts = candle[0]
    if isinstance(ts, str):
        timestamp = ts
    else:
        timestamp = datetime.fromtimestamp(float(ts)).isoformat()
    return {
        "ts": timestamp,
        "open": float(candle[1]),
        "high": float(candle[2]),
        "low": float(candle[3]),
        "close": float(candle[4]),
        "volume": int(candle[5] or 0),
    }


def fetch_minute_bars(
    cfg: KiteHistoricalConfig,
    window_start: str,
    window_end: str,
    *,
    http_get: HttpGet | None = None,
    max_attempts: int = 3,
    backoff_seconds: float = 1.0,
    sleep: Sleep = time.sleep,
) -> list[dict[str, Any]]:
    """Fetch one Kite historical window (caller should chunk large ranges)."""
    http_get = http_get or default_http_get
    if not cfg.api_key or not cfg.access_token:
        raise RuntimeError("STOXLA_KITE_API_KEY and STOXLA_KITE_ACCESS_TOKEN are required for Kite fetch.")

    start = _parse_window_datetime(window_start, end_of_day=False)
    end = _parse_window_datetime(window_end, end_of_day=True)
    query = urllib.parse.urlencode(
        {
            "from": start.strftime("%Y-%m-%d %H:%M:%S"),
            "to": end.strftime("%Y-%m-%d %H:%M:%S"),
            "continuous": "0",
            "oi": "0",
        }
    )
    url = f"{KITE_API_BASE}/instruments/historical/{cfg.instrument_token}/minute?{query}"
    headers = {"Authorization": f"token {cfg.api_key}:{cfg.access_token}"}
    attempts = max(1, int(max_attempts))
    for attempt in range(1, attempts + 1):
        try:
            payload = http_get(url, headers)
            break
        except Exception as error:  # noqa: BLE001
            if attempt >= attempts or not _retryable_fetch_error(error):
                raise
            sleep(max(0.0, float(backoff_seconds)) * (2 ** (attempt - 1)))
    else:  # pragma: no cover - loop either breaks or raises
        raise RuntimeError("Kite historical fetch did not produce a response.")
    candles = payload.get("data", {}).get("candles") or []
    return [normalize_kite_candle(row) for row in candles]


def fetch_minute_bars_chunked(
    cfg: KiteHistoricalConfig,
    window_start: str,
    window_end: str,
    *,
    chunk_days: int = 30,
    http_get: HttpGet | None = None,
    max_attempts: int = 3,
    backoff_seconds: float = 1.0,
    sleep: Sleep = time.sleep,
) -> list[dict[str, Any]]:
    """Respect Kite minute-history window limits by chunking the date range."""
    start = _parse_window_datetime(window_start, end_of_day=False).date()
    end = _parse_window_datetime(window_end, end_of_day=True).date()
    if start > end:
        return []

    bars: list[dict[str, Any]] = []
    cursor = start
    while cursor <= end:
        chunk_end = min(cursor + timedelta(days=chunk_days - 1), end)
        chunk_bars = fetch_minute_bars(
            cfg,
            cursor.isoformat(),
            chunk_end.isoformat(),
            http_get=http_get,
            max_attempts=max_attempts,
            backoff_seconds=backoff_seconds,
            sleep=sleep,
        )
        bars.extend(chunk_bars)
        cursor = chunk_end + timedelta(days=1)
    return bars


def _parse_window_datetime(value: str, *, end_of_day: bool) -> datetime:
    text = value.strip()
    if len(text) == 10:
        parsed = date.fromisoformat(text)
        if end_of_day:
            return datetime(parsed.year, parsed.month, parsed.day, 15, 30, 0)
        return datetime(parsed.year, parsed.month, parsed.day, 9, 15, 0)
    if "T" in text:
        text = text.replace("T", " ", 1)
    if "+" in text:
        text = text.split("+", 1)[0]
    return datetime.fromisoformat(text[:19])
