from __future__ import annotations

import os
from typing import Any

import httpx


class ConfigurationUnavailable(Exception):
    pass


class LaravelConfigurationClient:
    """Fetches a projection from Laravel; it intentionally has no DB client."""

    async def get(self) -> dict[str, Any]:
        url = os.getenv("STOX_LARAVEL_INTERNAL_URL", "").rstrip("/")
        key = os.getenv("STOX_AI_SERVICE_KEY", "")
        if not url or not key:
            raise ConfigurationUnavailable("Laravel internal configuration is not configured")
        try:
            async with httpx.AsyncClient(timeout=float(os.getenv("STOX_AI_CONFIG_TIMEOUT_SECONDS", "5"))) as client:
                response = await client.get(f"{url}/api/internal/v1/ai-runtime/configuration", headers={"X-StoX-AI-Service-Key": key})
                response.raise_for_status()
                payload = response.json()
        except httpx.HTTPError as error:
            raise ConfigurationUnavailable("Laravel configuration projection unavailable") from error
        if not payload.get("success") or not isinstance(payload.get("data"), dict):
            raise ConfigurationUnavailable("Laravel returned an invalid configuration projection")
        return payload["data"]

    async def record(self, event: dict[str, Any]) -> None:
        url, key = os.getenv("STOX_LARAVEL_INTERNAL_URL", "").rstrip("/"), os.getenv("STOX_AI_SERVICE_KEY", "")
        if not url or not key:
            return
        try:
            async with httpx.AsyncClient(timeout=5) as client:
                await client.post(f"{url}/api/internal/v1/ai-runtime/inference-events", headers={"X-StoX-AI-Service-Key": key}, json=event)
        except httpx.HTTPError:
            # Audit delivery is retried by deployment/observability paths; inference must fail independently.
            return
