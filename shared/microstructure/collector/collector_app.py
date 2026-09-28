"""Orchestrates command polling, bootstrap, aggregation, and Parquet flushes."""

from __future__ import annotations

import json
import os
import shutil
import time
from datetime import date, datetime, timedelta, timezone
from pathlib import Path
from typing import Any
from zoneinfo import ZoneInfo

from collector.kite_ticker_bridge import KiteTickerBridge
from collector.laravel_client import bootstrap_from_env
from collector.minute_aggregator import MinuteAggregator
from collector.finalization_state import FinalizationState
from collector.parquet_store import append_rows, is_partition_finalized, partition_dir, partition_row_count, write_finalization_manifest
from collector.raw_tick_spool import RawTickSpool
from collector.universe_audit import UniverseAudit


class CollectorApp:
    def __init__(self, command_file: Path, heartbeat_file: Path, data_root: Path) -> None:
        self.command_file = command_file
        self.heartbeat_file = heartbeat_file
        self.data_root = data_root
        self.manual_stop = False
        self.collector_state = "idle"
        self.last_command: str | None = None
        self.aggregator = MinuteAggregator()
        self.reconnect_count = 0
        self.subscribed_count = 0
        self.last_packet_at: str | None = None
        self.latest_error: str | None = None
        self.websocket_connected = False
        self._dry_run = os.environ.get("MICROSTRUCTURE_DRY_RUN", "").lower() in {"1", "true", "yes"}
        self._bridge = KiteTickerBridge(
            on_tick=self._handle_live_tick,
            on_connection_change=self._set_websocket_connected,
        )
        self._live_started = False
        self._last_universe_key: str | None = None
        self._pending_resubscribe = False
        self._backup_status = "not_run"
        self._market_timezone = ZoneInfo(os.environ.get("MICROSTRUCTURE_MARKET_TIMEZONE", "Asia/Kolkata"))
        self._coverage_day = self._market_day()
        self._coverage_counts: dict[str, int] = {}
        self._connection_was_lost = False
        self._raw_spool = RawTickSpool.from_env(self.data_root)
        self._universe_audit = UniverseAudit(Path(os.environ.get("MICROSTRUCTURE_UNIVERSE_AUDIT_FILE", str(self.data_root / "universe-audit.json"))))
        self._finalization = FinalizationState(
            Path(os.environ.get("MICROSTRUCTURE_FINALIZATION_STATE_FILE", str(self.data_root / "finalization-state.json"))),
            max_retries=int(os.environ.get("MICROSTRUCTURE_FINALIZATION_MAX_RETRIES", "3")),
        )

    def _market_day(self) -> date:
        return datetime.now(self._market_timezone).date()

    def _set_websocket_connected(self, connected: bool) -> None:
        if not connected and self.websocket_connected:
            self.aggregator.mark_collection_gap("collector_disconnected")
            self._connection_was_lost = True
        elif connected and self._connection_was_lost:
            self.aggregator.mark_collection_gap("reconnect_window")
            self._connection_was_lost = False
        self.websocket_connected = connected

    def _record_coverage(self, rows: list[dict[str, Any]]) -> None:
        day = self._market_day()
        if day != self._coverage_day:
            self._coverage_day = day
            self._coverage_counts = {}
        for row in rows:
            quality = str(row.get("coverage_class") or "unknown")
            self._coverage_counts[quality] = self._coverage_counts.get(quality, 0) + 1

    def _handle_live_tick(self, meta: dict[str, Any], tick: dict[str, Any]) -> None:
        now = datetime.now(timezone.utc)
        try:
            self._raw_spool.append(meta, tick, at=now)
        except OSError as exc:
            self.latest_error = f"raw_tick_spool: {exc}"
        self.aggregator.ingest(meta, tick, at=now)
        self.last_packet_at = now.isoformat()

    def read_command(self) -> dict[str, Any] | None:
        if not self.command_file.is_file():
            return None
        try:
            payload = json.loads(self.command_file.read_text(encoding="utf-8"))
        except (OSError, json.JSONDecodeError):
            return None
        return payload if isinstance(payload, dict) else None

    def write_heartbeat(self) -> None:
        self.heartbeat_file.parent.mkdir(parents=True, exist_ok=True)
        trading_day = self._market_day().isoformat()
        session_start = os.environ.get("MICROSTRUCTURE_MARKET_SESSION_START", "09:15")
        session_end = os.environ.get("MICROSTRUCTURE_MARKET_SESSION_END", "15:30")
        start_h, start_m = (int(part) for part in session_start.split(":", 1))
        end_h, end_m = (int(part) for part in session_end.split(":", 1))
        expected_minutes = max(0, (end_h * 60 + end_m) - (start_h * 60 + start_m))
        total_rows = sum(self._coverage_counts.values())
        expected_rows = expected_minutes * max(1, self.subscribed_count)
        payload = {
            "collector_state": self.collector_state,
            "websocket_connected": self.websocket_connected,
            "subscribed_instrument_count": self.subscribed_count,
            "last_packet_at": self.last_packet_at,
            "reconnect_count": self.reconnect_count,
            "coverage_summary": {
                "trading_day": trading_day,
                "expected_minute_count": expected_minutes,
                "observed_row_count": total_rows,
                "quality_counts": self._coverage_counts,
                "expected_instrument_minutes": expected_rows,
                "coverage_percent": round((total_rows / expected_rows) * 100, 2) if expected_rows else None,
            },
            "session_phase": self._session_phase(),
            "latest_finalized_partition": trading_day if is_partition_finalized(self.data_root, self._market_day()) else None,
            "backup_status": self._backup_status,
            "finalization": self._finalization.load(),
            "latest_error": self.latest_error,
            "updated_at": datetime.now(timezone.utc).isoformat(),
            "data_root": str(self.data_root),
            "raw_tick_spool": self._raw_spool.stats(),
            "universe_audit": self._universe_audit.load().get("latest"),
        }
        self.heartbeat_file.write_text(json.dumps(payload, indent=2), encoding="utf-8")

    def handle_command(self, command: str | None) -> None:
        if command is None or command == self.last_command:
            return
        self.last_command = command
        if command == "stop":
            self.manual_stop = True
            self.collector_state = "manually_stopped"
            self._stop_live()
        elif command == "start":
            self.manual_stop = False
            self.collector_state = "starting"
            self._pending_resubscribe = True
        elif command == "force_resubscribe":
            self.reconnect_count += 1
            self.collector_state = "resubscribing"
            self._pending_resubscribe = True
        elif command == "retry_finalization":
            self.finalize_today(force=True)
        elif command == "refresh_universe":
            self.collector_state = "refreshing_universe"
            self._pending_resubscribe = True
        elif command == "retry_backup":
            self._backup_today()

    def _stop_live(self) -> None:
        self._bridge.stop()
        self._live_started = False
        self.websocket_connected = False

    def flush_completed_minutes(self) -> None:
        now = datetime.now(timezone.utc)
        flushed = self.aggregator.flush_before(now)
        if flushed:
            self._record_coverage(flushed)
            append_rows(self.data_root, self._market_day(), flushed, part_name=f"part-{int(time.time())}.parquet")

    def _session_phase(self) -> str:
        current = datetime.now(self._market_timezone)
        start_hour, start_minute = (int(part) for part in os.environ.get("MICROSTRUCTURE_MARKET_SESSION_START", "09:15").split(":", 1))
        end_hour, end_minute = (int(part) for part in os.environ.get("MICROSTRUCTURE_MARKET_SESSION_END", "15:30").split(":", 1))
        if current.time() < current.replace(hour=start_hour, minute=start_minute, second=0, microsecond=0).time():
            return "pre_market"
        if current.time() > current.replace(hour=end_hour, minute=end_minute, second=0, microsecond=0).time():
            return "post_market"
        return "market"

    def finalize_today(self, force: bool = False) -> None:
        trading_day = self._market_day()
        if is_partition_finalized(self.data_root, trading_day):
            self.collector_state = "finalized"
            self._backup_today()
            return
        state = self._finalization.load()
        if not force and state.get("status") in {"finalization_failed", "backup_failed"} and not self._finalization.retry_allowed():
            self.collector_state = "finalization_retry_wait"
            return
        if not force and int(state.get("finalization_attempts", 0)) >= self._finalization.max_retries:
            self.collector_state = "finalization_failed"
            return
        self._finalization.mark_finalization_started(trading_day.isoformat())
        try:
            # At post-market finalization the active minute is complete too;
            # use the next minute as the exclusive cutoff.
            flushed = self.aggregator.flush_before(datetime.now(timezone.utc) + timedelta(minutes=1))
            if flushed:
                self._record_coverage(flushed)
                append_rows(self.data_root, trading_day, flushed, part_name=f"part-final-{int(time.time())}.parquet")
            row_count = partition_row_count(self.data_root, trading_day)
            write_finalization_manifest(
                self.data_root,
                trading_day,
                {"finalized_at": datetime.now(timezone.utc).isoformat(), "row_count": row_count},
            )
            self._finalization.mark_finalized(trading_day.isoformat(), row_count)
            self._raw_spool.prune_day(trading_day)
            self.collector_state = "finalized"
            self._backup_today()
        except Exception as exc:  # noqa: BLE001
            self._finalization.mark_finalization_failed(str(exc))
            self.collector_state = "finalization_failed"
            self.latest_error = f"finalization: {exc}"

    def _backup_today(self) -> None:
        trading_day = self._market_day()
        if not is_partition_finalized(self.data_root, trading_day):
            self._backup_status = "skipped_not_finalized"
            return
        source = partition_dir(self.data_root, trading_day)
        backup_root = Path(os.environ.get("MICROSTRUCTURE_BACKUP_ROOT", str(self.data_root.parent / "microstructure-backup")))
        target = backup_root / source.relative_to(self.data_root)
        try:
            staging = target.with_name(f".{target.name}.backup-staging")
            if staging.exists():
                shutil.rmtree(staging)
            shutil.copytree(source, staging)
            if target.exists():
                shutil.rmtree(target)
            staging.replace(target)
            self._backup_status = f"ok:{trading_day.isoformat()}"
            self._finalization.mark_backup(self._backup_status)
        except OSError as exc:
            self._backup_status = f"failed:{exc}"
            self._finalization.mark_backup("backup_failed", str(exc))

    def _universe_key(self, universe: list[dict[str, Any]]) -> str:
        tokens = sorted(int(u["source_instrument_token"]) for u in universe if u.get("source_instrument_token"))
        return ",".join(str(t) for t in tokens)

    def _ensure_live_collection(self, kite: dict[str, Any], universe: list[dict[str, Any]]) -> None:
        api_key = str(kite.get("api_key") or "")
        access_token = str(kite.get("access_token") or "")
        if api_key == "" or access_token == "":
            return

        universe_key = self._universe_key(universe)
        if self._live_started and not self._pending_resubscribe and universe_key == self._last_universe_key:
            return

        try:
            self._bridge.configure(api_key, access_token, universe)
            if self._live_started and self._pending_resubscribe:
                self._bridge.resubscribe()
            else:
                self._bridge.start()
            self._live_started = True
            self._last_universe_key = universe_key
            self._pending_resubscribe = False
            self.collector_state = "collecting"
            self.latest_error = None
        except Exception as exc:  # noqa: BLE001
            self.latest_error = str(exc)
            self.collector_state = "collector_error"
            self._live_started = False
            self.websocket_connected = False

    def tick_once(self) -> None:
        cmd = self.read_command()
        if cmd:
            self.handle_command(cmd.get("command"))

        if self.manual_stop:
            self.write_heartbeat()
            return

        # A failed finalization is retried by the process after its persisted
        # backoff, including after a service restart. Manual retry bypasses
        # the automatic retry budget through the command handler.
        if self._finalization.retry_allowed():
            self.finalize_today()

        try:
            refresh = self.collector_state == "refreshing_universe"
            bootstrap = bootstrap_from_env(refresh_universe=refresh)
            if refresh and bootstrap.get("universe"):
                self.collector_state = "collecting"
        except Exception as exc:  # noqa: BLE001
            self.latest_error = str(exc)
            self.collector_state = "awaiting_bootstrap"
            self.write_heartbeat()
            return

        if bootstrap.get("manual_hold"):
            self._stop_live()
            self.collector_state = "manually_stopped"
            self.write_heartbeat()
            return

        if not bootstrap.get("trading_session_day"):
            self._stop_live()
            self.collector_state = "outside_session"
            self.write_heartbeat()
            return

        phase = self._session_phase()
        if phase == "pre_market":
            self._stop_live()
            self.collector_state = "pre_market_ready"
            self.write_heartbeat()
            return
        if phase == "post_market":
            self._stop_live()
            self.finalize_today()
            self.write_heartbeat()
            return

        kite = bootstrap.get("kite")
        universe = bootstrap.get("universe") or []
        self.subscribed_count = len(universe)
        audit = self._universe_audit.record(universe, "manual_refresh" if refresh else "bootstrap")
        if audit.get("conflicts"):
            self.latest_error = "universe mapping conflicts detected"
        if not kite:
            self._stop_live()
            self.collector_state = "awaiting_kite_session"
            self.write_heartbeat()
            return

        if self._dry_run:
            for meta in universe:
                self.aggregator.ensure_instrument(meta)
            self._simulate_ticks(universe)
            self.collector_state = "collecting_dry_run"
            self.websocket_connected = True
        else:
            for meta in universe:
                self.aggregator.ensure_instrument(meta)
            self._ensure_live_collection(kite, universe)

        self.flush_completed_minutes()
        self.write_heartbeat()

    def _simulate_ticks(self, universe: list[dict[str, Any]]) -> None:
        now = datetime.now(timezone.utc)
        for meta in universe[:5]:
            self.aggregator.ingest(
                meta,
                {
                    "last_price": 100.0,
                    "volume_traded": 1000,
                    "depth": {
                        "buy": [{"price": 99.9, "quantity": 100}],
                        "sell": [{"price": 100.1, "quantity": 120}],
                    },
                },
                at=now,
            )
        self.last_packet_at = now.isoformat()

    def run_forever(self, interval_seconds: float = 5.0) -> None:
        while True:
            self.tick_once()
            time.sleep(interval_seconds)
