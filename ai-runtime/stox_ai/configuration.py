from __future__ import annotations

import asyncio
import os
from typing import Any

import httpx
from .outbox import DeliveryOutbox


class ConfigurationUnavailable(Exception):
    pass


class LaravelConfigurationClient:
    """Fetches a projection from Laravel; it intentionally has no DB client."""

    def __init__(self):
        self.outbox = DeliveryOutbox()

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

    async def send(self, endpoint: str, event: dict) -> dict:
        url, key = os.getenv("STOX_LARAVEL_INTERNAL_URL", "").rstrip("/"), os.getenv("STOX_AI_SERVICE_KEY", "")
        if not url or not key:
            raise ConfigurationUnavailable("Laravel internal transport is not configured")
        async with httpx.AsyncClient(timeout=5) as client:
            response = await client.post(f"{url}/api/internal/v1/ai-runtime/{endpoint}", headers={"X-StoX-AI-Service-Key": key}, json=event)
            response.raise_for_status()
            payload = response.json()
            if payload.get("success") is not True or not isinstance(payload.get("data"), dict):
                raise ConfigurationUnavailable("Laravel acknowledgement is invalid")
            expected = 'event_id' if endpoint == 'inference-events' else 'id'
            if payload['data'].get(expected) is None or (expected == 'id' and payload['data']['id'] != event['id']):
                raise ConfigurationUnavailable('Laravel acknowledgement identity is invalid')
            return payload['data']

    async def reserve(self, event: dict) -> dict:
        return await self.send('reservations', event)

    def settlement(self, event: dict):
        # Synchronous durable enqueue also runs during task cancellation.
        self.outbox.enqueue('settlements', event)

    async def record(self, event: dict[str, Any]) -> None:
        self.outbox.enqueue('inference-events', event)
        try:
            # Delivery backlog must not extend the interactive inference deadline.
            async with asyncio.timeout(1):
                await self.outbox.drain(self.send)
        except TimeoutError:
            pass  # Durable envelopes remain for the lifespan delivery worker.
