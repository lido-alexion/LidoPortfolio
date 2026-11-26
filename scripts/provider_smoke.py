#!/usr/bin/env python3
"""Nonblocking, redacted availability probes for public market providers."""

from __future__ import annotations

import datetime as dt
import time
import urllib.error
import urllib.request
from pathlib import Path


PROBES = (
    ("NSE", "https://www.nseindia.com/api/marketStatus"),
    ("Yahoo Finance", "https://query1.finance.yahoo.com/v8/finance/chart/INFY.NS?range=1d&interval=1d"),
)


def probe(label: str, url: str) -> tuple[str, int | str, int]:
    request = urllib.request.Request(
        url,
        headers={"User-Agent": "StoX-Provider-Smoke/1.0", "Accept": "application/json"},
    )
    started = time.monotonic()
    status: int | str
    try:
        with urllib.request.urlopen(request, timeout=10) as response:
            status = response.status
            response.read(1)  # Confirm a response body can be read; never retain or print it.
    except urllib.error.HTTPError as error:
        status = error.code
        error.close()
    except (TimeoutError, OSError, urllib.error.URLError) as error:
        # Exception text can contain provider detail. Emit only its type.
        status = f"unavailable ({type(error).__name__})"
    elapsed_ms = round((time.monotonic() - started) * 1000)
    return label, status, elapsed_ms


def main() -> int:
    now = dt.datetime.now(dt.timezone.utc).replace(microsecond=0).isoformat()
    rows = [probe(label, url) for label, url in PROBES]
    lines = [
        "# StoX live provider smoke report",
        "",
        f"Checked at: {now}",
        "",
        "Provider | HTTP status | Elapsed (ms)",
        "--- | ---: | ---:",
    ]
    lines.extend(f"{label} | {status} | {elapsed}" for label, status, elapsed in rows)
    lines.extend(("", "This report is informational; live provider results do not gate CI or deployment."))
    report = "\n".join(lines) + "\n"
    Path("provider-smoke-report.md").write_text(report, encoding="utf-8")
    print(report, end="")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
