"""HTTP client for StoX internal collector bootstrap API."""

from __future__ import annotations

import os
from typing import Any

import urllib.error
import urllib.request


def fetch_bootstrap(base_url: str, token: str, refresh_universe: bool = False) -> dict[str, Any]:
    query = "?refresh_universe=1" if refresh_universe else ""
    url = f"{base_url.rstrip('/')}/api/internal/microstructure-collector/bootstrap{query}"
    request = urllib.request.Request(
        url,
        headers={
            "Authorization": f"Bearer {token}",
            "Accept": "application/json",
        },
        method="GET",
    )
    try:
        with urllib.request.urlopen(request, timeout=30) as response:
            body = response.read().decode("utf-8")
    except urllib.error.HTTPError as exc:
        raise RuntimeError(f"bootstrap HTTP {exc.code}") from exc
    except urllib.error.URLError as exc:
        raise RuntimeError(f"bootstrap unreachable: {exc.reason}") from exc

    import json

    payload = json.loads(body)
    data = payload.get("data")
    if not isinstance(data, dict):
        raise RuntimeError("bootstrap response missing data object")
    return data


def bootstrap_from_env(refresh_universe: bool = False) -> dict[str, Any]:
    base = os.environ.get("STOX_APP_URL", "http://localhost")
    token = os.environ.get("MICROSTRUCTURE_COLLECTOR_INTERNAL_TOKEN", "")
    if not token:
        raise RuntimeError("MICROSTRUCTURE_COLLECTOR_INTERNAL_TOKEN is not set")
    return fetch_bootstrap(base, token, refresh_universe=refresh_universe)
