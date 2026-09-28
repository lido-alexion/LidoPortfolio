"""Kite Connect WebSocket (full mode) bridge for FEAT-063."""

from __future__ import annotations

import logging
import threading
from datetime import datetime, timezone
from typing import Any, Callable

logger = logging.getLogger(__name__)

try:
    from kiteconnect import KiteTicker
except ImportError:  # pragma: no cover
    KiteTicker = None  # type: ignore[misc, assignment]


class KiteTickerBridge:
    def __init__(
        self,
        on_tick: Callable[[dict[str, Any], dict[str, Any]], None],
        on_connection_change: Callable[[bool], None] | None = None,
    ) -> None:
        self._on_tick = on_tick
        self._on_connection_change = on_connection_change
        self._ticker: Any | None = None
        self._api_key: str | None = None
        self._access_token: str | None = None
        self._token_meta: dict[int, dict[str, Any]] = {}
        self._lock = threading.Lock()
        self._started = False

    def configure(self, api_key: str, access_token: str, universe: list[dict[str, Any]]) -> None:
        self._api_key = api_key
        self._access_token = access_token
        self._token_meta = {
            int(row["source_instrument_token"]): row for row in universe if row.get("source_instrument_token")
        }

    def start(self) -> None:
        if KiteTicker is None:
            raise RuntimeError("kiteconnect package is not installed")
        if not self._api_key or not self._access_token:
            raise RuntimeError("Kite credentials are not configured")
        with self._lock:
            if self._started and self._ticker is not None:
                return
            tokens = list(self._token_meta.keys())
            if not tokens:
                raise RuntimeError("Universe is empty — cannot subscribe")

            ticker = KiteTicker(self._api_key, self._access_token)
            ticker.on_ticks = self._handle_ticks
            ticker.on_connect = lambda _ws, _response: self._on_connect(ticker, tokens)
            ticker.on_close = lambda _ws, code, reason: self._on_close(code, reason)
            ticker.on_error = lambda _ws, code, reason: self._on_error(code, reason)
            ticker.on_reconnect = lambda _ws, attempts: self._on_reconnect(attempts)
            ticker.on_noreconnect = lambda _ws: self._on_noreconnect()

            self._ticker = ticker
            self._started = True
            ticker.connect(threaded=True)

    def stop(self) -> None:
        with self._lock:
            if self._ticker is not None:
                try:
                    self._ticker.close()
                except Exception:  # noqa: BLE001
                    logger.exception("Error closing Kite ticker")
            self._ticker = None
            self._started = False
        if self._on_connection_change:
            self._on_connection_change(False)

    def resubscribe(self) -> None:
        self.stop()
        self.start()

    def _on_connect(self, ticker: Any, tokens: list[int]) -> None:
        try:
            ticker.subscribe(tokens)
            ticker.set_mode(ticker.MODE_FULL, tokens)
        except Exception:  # noqa: BLE001
            logger.exception("Kite subscribe failed")
            return
        if self._on_connection_change:
            self._on_connection_change(True)

    def _handle_ticks(self, _ws: Any, ticks: list[dict[str, Any]]) -> None:
        now = datetime.now(timezone.utc)
        for tick in ticks:
            token = tick.get("instrument_token")
            if token is None:
                continue
            meta = self._token_meta.get(int(token))
            if meta is None:
                continue
            self._on_tick(meta, tick)

    def _on_close(self, code: int, reason: str) -> None:
        logger.warning("Kite ticker closed: %s %s", code, reason)
        if self._on_connection_change:
            self._on_connection_change(False)

    def _on_error(self, code: int, reason: str) -> None:
        logger.error("Kite ticker error: %s %s", code, reason)

    def _on_reconnect(self, attempts: int) -> None:
        logger.info("Kite ticker reconnect attempt %s", attempts)
        ticker = self._ticker
        if ticker is not None:
            # Kite may restore the socket without restoring the subscription
            # mode. Re-apply both against the complete active universe.
            self._on_connect(ticker, list(self._token_meta.keys()))

    def _on_noreconnect(self) -> None:
        logger.error("Kite ticker gave up reconnecting")
        if self._on_connection_change:
            self._on_connection_change(False)
