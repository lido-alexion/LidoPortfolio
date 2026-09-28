# StoX live microstructure collector (V8 FEAT-063)

Long-running Python service on the StoX VPS: Kite WebSocket `full` mode → 1-minute aggregates → schema-versioned Parquet.

## Layout

- `collector/` — runtime package (`python -m collector`)
- Parquet root (configured): `MICROSTRUCTURE_DATA_ROOT` (default `storage/app/microstructure` on Laravel)

## Laravel integration

- Admin status: `GET /api/microstructure-collector/status`
- Admin commands: `POST /api/microstructure-collector/command` with `command` ∈ `start`, `stop`, `force_resubscribe`, `retry_finalization`, `retry_backup`, `refresh_universe`
- Internal bootstrap (collector only): `GET /api/internal/microstructure-collector/bootstrap` with `Authorization: Bearer $MICROSTRUCTURE_COLLECTOR_INTERNAL_TOKEN`
- Command file: `MICROSTRUCTURE_COMMAND_FILE` (collector polls and acknowledges)
- Heartbeat file: `MICROSTRUCTURE_HEARTBEAT_FILE` (collector writes JSON for the Admin UI)

Environment for the Python process:

- `STOX_APP_URL` — Laravel base URL (e.g. `https://stoxla.in`)
- `MICROSTRUCTURE_COLLECTOR_INTERNAL_TOKEN` — must match Laravel `config/microstructure_collector.php`
- `MICROSTRUCTURE_DRY_RUN=1` — simulate ticks and write sample Parquet without Kite WebSocket
- `MICROSTRUCTURE_MARKET_TIMEZONE` — exchange timezone (default `Asia/Kolkata`)
- `MICROSTRUCTURE_MARKET_SESSION_START` / `MICROSTRUCTURE_MARKET_SESSION_END` — session bounds (defaults `09:15` / `15:30`)
- `MICROSTRUCTURE_FINALIZATION_STATE_FILE` — durable retry/backup state (default `$MICROSTRUCTURE_DATA_ROOT/finalization-state.json`)
- `MICROSTRUCTURE_FINALIZATION_MAX_RETRIES` — bounded automatic finalization attempts (default `3`)

Successful Kite login for `MICROSTRUCTURE_KITE_USER_ID` queues `start` unless a manual hold is active.

## Python modules

- `collector/minute_aggregator.py` — minute buckets + coverage metadata
- `collector/parquet_store.py` — ZSTD Parquet partitions under `schema_v1/year=/month=/date=`
- `collector/collector_app.py` — command loop + bootstrap + dry-run path
- `tests/test_minute_aggregator.py` — `PYTHONPATH=. python3 -m unittest tests/test_minute_aggregator.py`

## Deployment

Install the declared dependencies into the interpreter referenced by
`deploy/systemd/stoxla-microstructure-collector.service`, create the service
environment file with the internal token and Kite settings, then run:

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now stoxla-microstructure-collector.service
sudo systemctl status stoxla-microstructure-collector.service
```

The collector uses KiteTicker `full` mode in production. Keep
`MICROSTRUCTURE_DRY_RUN=1` for a dependency-only smoke test; it must not be
used as the production setting.
