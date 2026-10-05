# StoX V8 Intraday ML Historical Data Platform Specification

| Field | Value |
|---|---|
| **Feature** | V4-FEAT-065 — Intraday ML Historical Data Platform |
| **Version target** | V8 |
| **Status** | FROZEN — implementation-ready |
| **Canonical path** | `docs/archive/specs/V8-Intraday-ML-Historical-Data-Platform-Specification.md` |
| **Supersedes** | `docs/archive/specs/V8-ML-Intraday-Market-Data-Platform.md` |
| **Primary source** | Zerodha Kite Connect historical API |
| **Universe** | Current NIFTY 500 fixed universe |
| **History target** | Up to 8 years of available 1-minute OHLCV |
| **Canonical storage** | Apache Parquet |
| **Analytical stack** | DuckDB + Polars/Python |
| **Initial receiving/holding machine** | Windows 11 laptop with sufficient local storage; verified batches are manually offloaded to NTFS external storage |
| **Canonical research/training machine** | MacBook Pro |
| **Downstream ML epic** | V4-FEAT-057 — ML Feature Engineering, Model Training & Validation |
| **Production lifecycle epic** | V4-FEAT-056 — ML Lifecycle Automation, Deployment & Operations |
| **Related prospective-data epic** | V4-FEAT-063 — Live Microstructure Data Collection |

---

## 1. Purpose

V4-FEAT-065 builds the historical intraday research-data foundation for StoX machine-learning work.

The epic owns acquisition, validation, storage and analytical access for a large 1-minute historical market-data corpus. It does **not** own model-training logic itself. The canonical corpus is handed to V4-FEAT-057 for feature engineering, model training and validation.

The intended operating model is:

```text
Kite historical source
        |
        v
StoX VPS backfill and verified daily batches
        |
        v
Windows 11 initial receipt / holding
        |
        v
Manual offload to NTFS external storage
        |
        v
MacBook canonical Parquet corpus
        |
        +--> DuckDB / Polars research
        +--> PIT-safe dataset construction
        |
        v
FEAT-057 model training + validation
        |
        v
Approved/versioned model artifact
        |
        v
StoX VPS / FEAT-056 production lifecycle + inference
```

The Windows laptop is the initial receiving/holding machine; it does not replace the MacBook as the canonical research-data home or heavy offline research/training workstation. The user manually offloads verified batches to NTFS external storage and later copies them to the MacBook. Kite acquisition and the secure VPS-to-Windows transfer are specified by V9-DATA-002; this V8 epic remains the owner of the canonical Parquet and research contract. The StoX VPS also remains focused on production serving, FEAT-063 live collection, model lifecycle and production inference.

## 2. Frozen product decisions

| Decision | Frozen choice |
|---|---|
| 065-01 | The Windows 11 laptop is the initial receiving/holding machine for verified historical-data batches. The user manually offloads batches to NTFS external storage and later copies them to the MacBook Pro, which remains the canonical research-data home and heavy offline ML research/training machine. Approved model artifacts can later be deployed to the StoX VPS for production inference. |
| 065-02 | Apache Parquet is the canonical historical storage format. MySQL/MariaDB is not the primary repository for the minute-level corpus. |
| 065-03 | Use the **current NIFTY 500 fixed universe** for V8. Historical constituent reconstruction is out of scope and survivorship bias is explicitly accepted. |
| 065-04 | Backfill **up to 8 years** of available 1-minute OHLCV, subject to actual provider/instrument availability. |
| 065-05 | Zerodha Kite Connect historical API is the initial historical source. The analytical schema must not be permanently coupled to Kite transport details. |
| 065-06 | Use DuckDB for large SQL scans/joins/aggregations and Polars/Python for data preparation, feature engineering and ML dataset construction. |
| 065-07 | Historical import must be resumable and idempotent, with durable checkpoints, bounded retry/backoff, explicit 429/error handling, duplicate prevention, failed-window tracking, progress reporting and pause/resume. |
| 065-08 | Include selected broad-market and sector indices alongside the stock corpus so later research can build market-relative, breadth, sector-relative and regime features. |
| 065-09 | Do not introduce ClickHouse initially. Reconsider only if StoX later needs continuously interactive, multi-user analytics over the large corpus. |
| 065-10 | All downstream research datasets must remain point-in-time safe. |
| 065-11 | Prospective live microstructure collection remains separate under V4-FEAT-063. FEAT-065 owns historical/offline data only. |
| 065-12 | Historical derivative/Open-Interest enrichment is optional Dataset B work and must not block the core Dataset A OHLCV corpus. |
| 065-13 | Implement in stages: POC -> full backfill -> downstream feature/model research. |
| 065-14 | Keep the storage/analytical architecture source-agnostic enough to support a future alternate historical provider. |
| 065-15 | StoX will build **no automated backup mechanism** for the Mac-hosted historical corpus. The user will manually offload verified incoming batches from the Windows holding machine to NTFS external storage and later copy them to the Mac; ongoing manual external-disk copies of canonical Parquet and metadata remain the backup process. |

---

## 3. Dataset A — canonical historical corpus

Dataset A consists of:

- current NIFTY 500 stocks;
- up to 8 years of available 1-minute OHLCV;
- selected broad-market indices;
- selected sector indices;
- source/provenance metadata;
- schema/version metadata;
- coverage and quality metadata required for reproducible research.

Canonical candle identity/value fields SHALL include at least:

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
schema_version
```

Implementation may add additional non-destructive provenance/quality fields where useful.

The logical upper-order scale remains approximately:

```text
500 stocks
x 375 trading minutes/day
x 250 trading days/year
x 8 years
≈ 375,000,000 rows
```

Actual row count will be lower because of listings, suspensions, missing candles and source availability.

---

## 4. Universe policy and survivorship bias

V8 intentionally uses the **current NIFTY 500** as a fixed research universe for the historical backfill.

The following are explicitly not required for V8:

- historical constituent membership reconstruction;
- delisted-company reconstruction solely to remove survivorship bias;
- reconstruction of every historical index change.

The fixed-universe assumption SHALL be recorded prominently in dataset metadata and downstream research documentation so validation results are interpreted correctly.

This accepted simplification does not waive point-in-time safety for the actual features and labels generated from the corpus.

---

## 5. Historical source and provider abstraction

Zerodha Kite Connect is the initial source for 1-minute historical candles.

The importer SHALL isolate provider-specific concerns such as:

- instrument tokens;
- request-window limits;
- authentication/session details;
- pacing/rate limits;
- provider response normalization.

The canonical Parquet schema and downstream FEAT-057 research code SHALL consume normalized data rather than depend directly on Kite API response structures.

A future alternate source may be added without redesigning the analytical dataset contract.

---

## 6. Backfill execution

The historical importer SHALL be designed for a long-running offline backfill rather than one fragile monolithic job.

Required characteristics:

- configurable request pacing below provider limits;
- instrument/date-window work units;
- resumable checkpoints;
- idempotent reruns;
- bounded exponential or equivalent retry/backoff;
- explicit rate-limit handling;
- durable failed-window registry;
- duplicate prevention;
- deterministic output locations;
- progress reporting by instruments/windows/date coverage;
- pause/resume;
- safe process restart;
- validation before a window/partition is considered complete.

There is no requirement to maximize API throughput. Reliability and resumability are more important than finishing the backfill as quickly as technically possible.

---

## 7. Canonical storage architecture

The canonical analytical store is **Apache Parquet on the MacBook**. The Windows laptop is an initial receiving/holding location only; it is not a competing canonical corpus.

The implementation SHALL prefer partitioning that supports efficient date/instrument pruning without creating pathological numbers of tiny files.

A representative starting layout is:

```text
data/ml/intraday/
  schema_v1/
    year=2026/
      month=09/
        ...parquet
```

Exact partition granularity and file sizing are implementation decisions and should be validated in the POC.

Planning assumptions from the architecture phase remain:

- roughly 10–30 GB compressed Parquet for the core 375M-row OHLCV upper-bound, subject to schema/types/compression;
- substantially more local workspace may be needed for derived features, DuckDB spill, model datasets and experiments;
- a practical working-space budget around 150–250 GB is reasonable.

Temporary/derived data should remain reproducible where practical rather than becoming another canonical store.

---

## 8. Backup boundary

FEAT-065 SHALL NOT implement an automated backup/replication subsystem.

Operational assumption:

- the active canonical corpus lives on the MacBook;
- verified delivery batches are initially received/held on the Windows laptop, manually offloaded to NTFS external storage, then copied to the MacBook;
- the user periodically copies the canonical Parquet corpus and essential metadata to an external disk manually;
- temporary caches, DuckDB spill files and reproducible intermediate datasets do not require backup;
- loss of the active corpus is recoverable by rerunning the historical backfill, although doing so has a time cost.

The implementation should keep canonical data and essential metadata in a clear directory structure so manual external-disk copying is straightforward.

---

## 9. Analytical access

Use:

- **DuckDB** for SQL directly over Parquet, large scans, joins, filters and aggregations;
- **Polars/Python** for dataframe transformations, feature construction and ML-ready dataset assembly.

Do not load the complete corpus into memory unnecessarily.

The Windows downloader, local receipt/integrity verification, NTFS archive and manual Mac handoff are specified under SKR-001 in StoX-Kite-Rain; end-to-end acceptance remains pending in that epic. VPS staging and secure transfer are specified by V9-DATA-002. FEAT-065 POC acceptance begins when a representative verified batch is present in the canonical Mac corpus. It validates the Parquet schema/provenance, non-destructive adoption, and DuckDB/Polars access on the Mac before the full corpus is accepted for research. FEAT-065 does not duplicate the downloader or transfer acceptance.

---

## 10. Point-in-time safety

All downstream datasets derived from FEAT-065 must be point-in-time safe.

Requirements include:

- no future candles in any feature row;
- rolling calculations terminate at the observation timestamp;
- same-time cross-sectional features use only information available at that timestamp;
- fitted preprocessing uses training partitions only;
- train/validation/test partitions remain chronological;
- purge/embargo rules are applied where FEAT-057 requires them;
- corporate-action/data-correction transformations remain reproducible and versioned;
- source corrections must not silently mutate research reproducibility.

Detailed feature definitions, feature selection, model fitting, calibration and chronological validation belong to **V4-FEAT-057**.

---

## 11. Selected indices

The historical platform SHALL support configured broad-market and sector indices in addition to NIFTY 500 equities.

These series exist to support later FEAT-057 features such as:

- market-relative return;
- sector-relative return;
- breadth/regime context;
- cross-sectional dispersion;
- volatility regime;
- market/sector momentum.

Index series must be timestamp-aligned with the equity corpus and carry the same provenance/coverage discipline.

---

## 12. Dataset B — optional derivative/OI enrichment

Derivative enrichment is secondary and non-blocking.

Where useful historical data exists, a later increment may add normalized fields such as:

```text
futures_ohlcv
open_interest
oi_change
price_oi_interaction
volume_oi_interaction
```

Failure, poor coverage or absence of derivative history must not delay Dataset A acceptance.

---

## 13. Relationship to FEAT-063

V4-FEAT-063 owns prospective Kite WebSocket `full`-mode collection and durable one-minute microstructure data.

V4-FEAT-065 owns historical 1-minute OHLCV acquisition and offline analytical storage.

The separation is deliberate:

- FEAT-063 data is prospective and largely irreplaceable if a trading day is missed;
- FEAT-065 data is historical and can be backfilled later.

Therefore FEAT-063 remains the earlier operational priority even though both datasets may later be consumed together by FEAT-057.

---

## 14. Relationship to ML training and production

FEAT-065 is **not** the model-training epic.

V4-FEAT-057 consumes FEAT-065 data to perform:

- detailed feature engineering;
- feature selection;
- PIT-safe training-dataset construction;
- candidate model training;
- calibration;
- repeated chronological validation;
- comparison against deterministic StoX baselines and active models;
- production-candidate evidence generation.

Heavy offline FEAT-057 research/training may run on the MacBook because the historical corpus is local there.

Approved/versioned model artifacts may then be transferred to the StoX VPS. V4-FEAT-056 owns the production model lifecycle, including registration, explicit Admin promotion, rollback, retained model versions and production health/drift monitoring.

Production inference should not require the complete historical corpus to reside on the VPS.

---

## 15. Implementation stages

### Stage 1 — POC

Use tens of NIFTY 500 stocks plus representative indices to validate:

- Kite historical retrieval;
- actual request throughput and provider limits;
- authentication/session behavior;
- retry/resume/idempotency;
- normalized schema;
- Parquet compression;
- partition/file layout;
- coverage validation;
- DuckDB scan/query performance;
- Polars processing performance;
- basic PIT-safe derived-feature construction.

The POC should establish safe implementation defaults before scaling to the full corpus.

### Stage 2 — full Dataset A backfill

Expand to:

- current NIFTY 500;
- selected indices;
- up to 8 years of available 1-minute history;
- complete provenance/coverage reporting;
- repair of failed windows.

### Stage 3 — FEAT-057 research handoff

Use the canonical corpus for technical/market feature engineering, model-dataset construction, training and chronological validation under FEAT-057.

---

## 16. Acceptance criteria

FEAT-065 is complete when:

1. Historical importer retrieves configured 1-minute OHLCV ranges while honoring provider constraints.
2. Import is resumable, restart-safe and idempotent.
3. Failed windows are explicitly visible and repairable.
4. Canonical history is stored as schema-versioned Parquet rather than primarily as MySQL rows.
5. Current-NIFTY-500 fixed-universe/survivorship-bias assumptions are explicitly recorded.
6. DuckDB can directly query representative and full Parquet partitions.
7. Polars/Python can construct PIT-safe ML-ready datasets from the canonical corpus.
8. Selected market/sector indices are backfilled and timestamp-aligned.
9. Coverage and missingness reports exist by instrument/date range.
10. Source/provenance metadata is retained.
11. Provider-specific API details do not leak into the canonical analytical contract unnecessarily.
12. After SKR-001/V9-DATA-002 delivers a representative verified batch, FEAT-065 validates its non-destructive adoption into the canonical Mac corpus and confirms representative Parquet/DuckDB/Polars research workloads before the full corpus is accepted.
13. Dataset B remains optional and non-blocking.
14. FEAT-063 remains independently deployable and is not coupled to completion of this epic.
15. FEAT-057 can consume the resulting corpus without depending on retired FEAT-058/059 contracts.
16. No automated FEAT-065 backup subsystem is introduced; canonical files are arranged so manual external-disk backup is straightforward.
17. A trained/approved model artifact can be deployed separately to the VPS without requiring the historical corpus itself to be copied there.

---

## 17. Out of scope

This epic does not own:

- VPS batch staging, secure delivery and delivery catalog — V9-DATA-002;
- Windows batch downloader, receipt verification and NTFS archive workflow — SKR-001 in StoX-Kite-Rain;
- live order-book/microstructure collection — V4-FEAT-063;
- detailed feature definitions/selection — V4-FEAT-057;
- model training/calibration/validation contract — V4-FEAT-057;
- model deployment/promotion/rollback/drift operations — V4-FEAT-056;
- historical NIFTY 500 membership reconstruction;
- mandatory derivative history;
- automated cloud/off-site backup;
- continuous multi-user OLAP infrastructure such as ClickHouse;
- production storage of the entire historical corpus on the StoX VPS.

---

## 18. Final boundary

**V4-FEAT-065 owns:**

- historical 1-minute OHLCV acquisition;
- current-NIFTY-500 fixed-universe corpus;
- selected historical index acquisition;
- source normalization/provenance;
- quality and coverage reporting;
- initial receipt/holding on the Windows laptop with manual NTFS external-storage handoff;
- schema-versioned canonical Parquet storage on the MacBook;
- resumable/idempotent historical backfill;
- DuckDB/Polars analytical access;
- reusable PIT-safe data foundation for downstream research.

**V4-FEAT-063 owns:** prospective live microstructure data.

**V4-FEAT-057 owns:** feature engineering, ML dataset construction, model training, calibration and validation.

**V4-FEAT-056 owns:** production model lifecycle, promotion/rollback, deployment operations and production monitoring.

The earlier FEAT-058 and FEAT-059 responsibilities are consolidated into FEAT-057 and are not separate active dependencies.
