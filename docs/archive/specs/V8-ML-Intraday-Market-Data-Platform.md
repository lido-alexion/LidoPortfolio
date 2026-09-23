# StoX V8 Wishlist — Intraday ML Historical Data Platform

| Field | Value |
|---|---|
| **Epic** | V4-FEAT-062 |
| **Title** | Intraday ML Historical Data Platform |
| **Release** | V8 |
| **Status** | WISHLIST / ARCHITECTURE DIRECTION AGREED |
| **Primary data source** | Zerodha Kite Connect historical API |
| **Universe** | Current NIFTY 500 fixed universe |
| **History target** | Up to 8 years of 1-minute OHLCV |
| **Primary analytical storage** | Apache Parquet |
| **Primary analytical query/processing** | DuckDB + Polars/Python |
| **Related epics** | V4-FEAT-058, V4-FEAT-059, V4-FEAT-063 |

## 1. Product intent

Create the historical intraday data foundation for StoX ML research using a fixed universe of the **current NIFTY 500 stocks** and up to **8 years of 1-minute OHLCV** where Kite has data.

This epic is intentionally historical/offline and may be implemented after live Dataset C collection begins.

The user explicitly accepts survivorship bias from using today's NIFTY 500 constituents. Historical constituent reconstruction and delisted-company inclusion are not required for V8 because the research goal is stock-price trend learning within a sensible current universe rather than modelling bankruptcy/delisting risk.

## 2. Scope

Dataset A consists of:

- current NIFTY 500 stocks;
- up to 8 years of 1-minute OHLCV;
- selected broad-market and sector indices;
- source/provenance metadata needed for reproducible research.

Canonical candle fields:

```text
instrument_id
exchange
tradingsymbol
timestamp
open
high
low
close
volume
source
source_instrument_token
```

Open Interest/derivative enrichment remains optional Dataset B work where usable history exists.

## 3. Scale

Approximate upper-order row count:

```text
500 stocks
× 375 trading minutes/day
× 250 trading days/year
× 8 years
≈ 375,000,000 rows
```

Actual count will be lower where stocks were listed later, trading was suspended, candles are absent or source history is unavailable.

This reduced scope is deliberately chosen to keep the dataset large enough for serious ML research while remaining comfortable on a single capable workstation.

## 4. Historical source decision

Use **Zerodha Kite Connect** as the initial source.

Reasons:

- inexpensive compared with specialist historical-data vendors;
- useful 1-minute historical depth extending approximately to 2015 where available;
- NSE/BSE instrument and index support;
- straightforward historical candle API;
- adequate for the accepted fixed-current-universe research objective.

Alternative sources such as NSE historical trade data, Global Datafeeds or TrueData remain future options if a research need emerges.

The analytical schema must not be permanently coupled to Kite-specific transport details.

## 5. Backfill strategy

Assuming approximately 30-day request windows, 8 years requires roughly:

```text
~97 requests/stock
× 500 stocks
≈ 48,500 historical API requests
```

At a nominal 3 requests/second historical-data limit, the pure rate-limit floor is about **4.5 hours**. Real elapsed time will be longer because of network latency, retries, authentication/session handling and validation.

There is no business need to saturate the limit. The importer may run conservatively over days or longer.

Requirements:

- configurable pacing below provider limits;
- resumable/idempotent execution;
- per-instrument/date-window checkpoints;
- bounded retry/backoff;
- explicit 429/error handling;
- duplicate prevention;
- durable failed-window list;
- progress reporting;
- pause/resume support;
- source/provenance retention.

## 6. Storage architecture

Do **not** use MySQL as the primary repository for hundreds of millions of minute candles.

Use **Apache Parquet** for canonical analytical history.

Rationale:

- columnar storage;
- strong compression;
- efficient column and predicate pruning;
- direct support from DuckDB/Polars/Python;
- portable and licence-free;
- well suited to append-oriented historical analytical data.

Planning estimate for 375M OHLCV rows is roughly **10–30 GB compressed Parquet**, subject to final schema/types/compression.

Allow substantially more workspace for derived features, temporary DuckDB spill, experiments and backups. A practical research workspace budget is roughly **150–250 GB**.

## 7. Query/processing architecture

Use:

- **DuckDB** for SQL directly over Parquet, large scans, joins and aggregations;
- **Polars/Python** for feature engineering and ML dataset construction.

Do not introduce ClickHouse initially.

ClickHouse remains a future option only if StoX later needs continuously interactive, multi-user analytics over the large intraday corpus.

## 8. Workstation suitability

The available MacBook Pro is suitable as the primary research machine:

- MacBook Pro 16-inch (2021), MacBookPro18,1;
- Apple M1 Pro, 10 CPU cores;
- 32 GB unified memory;
- 1 TB internal SSD;
- approximately 540 GB free at the time of planning.

This is sufficient for the 500-stock/8-year Dataset A target and expected DuckDB/Polars research workload.

The existing production VPS can remain focused on StoX serving/MariaDB rather than being upgraded solely for historical ML processing.

## 9. Derived research context

The historical corpus should support later generation of:

- multi-horizon returns and momentum;
- volatility/range/candle features;
- volume/relative-volume features;
- market breadth;
- cross-sectional ranks/dispersion;
- market/index-relative returns;
- sector-relative context;
- session/time features.

Detailed feature definitions, selection and evidence belong to **V4-FEAT-058**.

Repeated chronological validation and promotion evidence belong to **V4-FEAT-059**.

## 10. Dataset B — optional derivative enrichment

Where Kite provides useful historical derivative/Open-Interest data, the research platform may later add:

```text
futures OHLCV
open_interest
OI change
price/OI interactions
volume/OI interactions
```

This is secondary and must not block Dataset A.

## 11. Dataset C is now a separate epic

Prospective live order-book/trade-microstructure collection is **not owned by this epic anymore**.

It has been split into:

> **V4-FEAT-063 — Live Microstructure Data Collection**

Reason: historical OHLCV can be backfilled later, while Kite live microstructure data cannot be reconstructed later at comparable depth. V4-FEAT-063 can therefore be prioritised and started immediately without waiting for this historical backfill.

## 12. Point-in-time requirements

All derived research datasets must remain point-in-time safe:

- no future candles;
- rolling calculations terminate at the observation timestamp;
- same-time cross-sectional context uses only information available by that timestamp;
- fitted preprocessing uses training partitions only;
- train/validation/test splits remain chronological with appropriate embargo/purge rules;
- data corrections/corporate-action handling remain reproducible/versioned.

For V8, the **current NIFTY 500 fixed universe** is an explicit accepted simplification and is not treated as a defect requiring historical constituent reconstruction.

## 13. Initial implementation stages

### Stage 1 — POC

Use tens of NIFTY 500 stocks plus relevant indices to validate:

- historical retrieval;
- observed API throughput;
- retry/resume behaviour;
- Parquet schema/compression;
- partition layout;
- DuckDB/Polars performance;
- basic feature generation.

### Stage 2 — full backfill

Expand to current NIFTY 500 and up to 8 years of available 1-minute history.

### Stage 3 — feature/model research

Hand the canonical corpus to V4-FEAT-058/V4-FEAT-059 research and validation workflows.

## 14. Initial acceptance criteria

1. Historical importer retrieves 1-minute OHLCV for configured NIFTY 500 instruments/date ranges while honouring Kite limits.
2. Import is resumable and idempotent.
3. Failed windows are visible and repairable.
4. Raw/canonical history is stored as schema-versioned Parquet, not primarily as MySQL rows.
5. DuckDB can query the Parquet corpus directly.
6. Polars/Python can build ML-ready datasets from the same canonical files.
7. Selected indices can be backfilled and timestamp-aligned.
8. Coverage/missingness reports are available.
9. The current-NIFTY-500 fixed-universe assumption is recorded in dataset metadata.
10. Full Dataset A target is approximately 375M maximum logical minute rows before availability reductions.
11. The architecture remains compatible with a future alternate historical data source.
12. Dataset C live collection remains independently deployable/prioritisable through V4-FEAT-063.

## 15. Boundary

**V4-FEAT-062 owns:** historical 1-minute OHLCV acquisition, Parquet historical storage, historical index acquisition, quality/provenance, and reusable analytical access.

**V4-FEAT-063 owns:** prospective Kite WebSocket full-mode collection and durable minute-level microstructure storage.

**V4-FEAT-058 owns:** detailed technical/market feature engineering and feature selection.

**V4-FEAT-059 owns:** chronological validation and evidence-based promotion criteria.

**V4-FEAT-056 owns:** scheduled production retraining/deployment operations once selected features/models enter the production lifecycle.
