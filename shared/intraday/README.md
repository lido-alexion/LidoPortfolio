# StoX intraday historical corpus (FEAT-065)

Mac-hosted **1-minute OHLCV** research corpus for ML feature engineering (FEAT-057).

- Canonical format: **Apache Parquet** under `shared/intraday/corpus/` (override with `STOXLA_INTRADAY_CORPUS_ROOT`).
- Universe: current **NIFTY 500** (survivorship accepted for V8).
- Checkpoint rows: `stox_intraday_backfill_checkpoints` (Laravel API `GET /api/v1/admin/intraday-platform`).
- Live ticks/minute bars remain **FEAT-063** (`shared/microstructure/`).

## Backfill worker

`backfill_worker.py` — dry-run by default; with `STOXLA_INTRADAY_BACKFILL_INTERNAL_TOKEN` and `--apply`, posts checkpoints to:

- `GET /api/internal/intraday-backfill/plan`
- `POST /api/internal/intraday-backfill/checkpoints`

Example:

```bash
export STOXLA_INTRADAY_BACKFILL_INTERNAL_TOKEN=your-token
export STOXLA_KITE_API_KEY=your-kite-key
export STOXLA_KITE_ACCESS_TOKEN=your-session-token
export STOXLA_KITE_INSTRUMENT_TOKEN=738561
python shared/intraday/backfill_worker.py --symbol RELIANCE --window-start 2024-01-01 --window-end 2024-01-31 --apply
```

Apply mode:

1. Fetches 1m candles from Kite when `STOXLA_KITE_*` credentials and `--instrument-token` (or env token) are set; or
2. Writes bars from `--bars-json path/to/bars.json`.

For the pinned current NIFTY 500 research universe, pass an explicit current
symbol/token manifest with `--universe-map`. The worker processes symbols in
stable sorted order, deduplicates them, and emits one checkpoint per symbol;
it never reconstructs historical membership or substitutes a future universe.
For example:

```bash
python shared/intraday/backfill_worker.py \
  --universe-map shared/intraday/data/current_nifty500_tokens.json \
  --window-start 2024-01-01 --window-end 2024-01-31 --apply
```

The manifest is an explicit operator-supplied current-universe snapshot and
must be refreshed/audited before a new corpus run. It is not used by FEAT-057
production features and does not add an automated backup subsystem.

Selected broad-market and sector indices use a separate `--index-map` manifest
with the same `SYMBOL -> Kite token` format. They are stored with
`exchange=NSE_INDEX` and receive independent checkpoints:

```bash
python shared/intraday/backfill_worker.py \
  --index-map shared/intraday/data/selected_indices_tokens.json \
  --window-start 2024-01-01 --window-end 2024-01-31 --apply
```

The index manifest is operator-supplied and auditable; the worker never infers
index membership or substitutes current equity constituents.

Parquet lands under `schema_v1/year=YYYY/month=MM/`. Coverage inventory: `python shared/intraday/coverage_report.py --corpus-root shared/intraday/corpus`. DuckDB/Polars helpers: `shared/intraday/dataset_builder.py`.
