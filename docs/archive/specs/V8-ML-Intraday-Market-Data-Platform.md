# StoX V8 Wishlist — Intraday ML Market Data Platform

| Field | Value |
|---|---|
| **Epic** | V4-FEAT-062 |
| **Title** | Intraday ML Market Data Platform |
| **Release** | V8 |
| **Status** | WISHLIST / ARCHITECTURE DIRECTION AGREED |
| **Primary data source** | Zerodha Kite Connect |
| **Primary analytical storage** | Apache Parquet |
| **Primary analytical query/processing** | DuckDB + Polars/Python |
| **Related V8 epic** | V4-FEAT-058 — ML Technical & Market Feature Engineering |

## 1. Product intent

Create a scalable intraday market-data foundation for StoX ML research so historical and prospective market behaviour can be analysed to discover statistical relationships, lead/lag effects and other predictive structure that may improve future-return or price-movement prediction.

The initial research focus is **1-minute market data**. The system should support large-scale historical backfill, cross-sectional analysis across the investable universe, derived market/sector context and later enrichment with live market-microstructure data.

The platform is a data/research capability. It does not assume that any feature or model is predictive merely because it is available. Feature and model promotion remain evidence-driven and subject to StoX's existing leakage-safe validation and model-promotion controls.

## 2. Scope and research objective

The intended ML workflow is:

```text
historical + prospective market data
    -> point-in-time feature engineering
    -> statistical / ML research
    -> chronological out-of-sample evaluation
    -> retain only reproducible predictive signals
    -> candidate models
    -> existing StoX promotion / deployment controls
```

Research may include:

- regression and nonlinear relationships between past market state and future outcomes;
- lead/lag relationships between stocks, indices and sectors;
- market-breadth and cross-sectional relationships;
- momentum, volatility, liquidity and volume interactions;
- later, order-book and trade-microstructure relationships for short-horizon prediction.

The epic is primarily about **data acquisition, durable analytical storage and reusable dataset construction**. Detailed feature selection remains owned by V4-FEAT-058.

## 3. Historical data source decision

### 3.1 Primary source — Zerodha Kite Connect

Use **Zerodha Kite Connect** as the initial source for historical intraday market data.

Reasons:

- low subscription cost relative to specialised market-data vendors;
- historical 1-minute OHLCV availability for equities, with useful depth extending approximately to 2015 where the instrument existed and Zerodha has data;
- support for NSE/BSE instruments and market indices;
- historical candle API suitable for controlled bulk backfill;
- live WebSocket market-data feed available for prospective enrichment;
- ability to retrieve Open Interest where supported by the instrument/history contract.

StoX already has a substantial daily historical-price dataset. Kite is therefore introduced primarily to add **intraday history and future live/microstructure data**, not to replace the existing daily-price source merely for duplication.

### 3.2 Alternative/future sources

Do not couple the analytical store or feature pipeline permanently to Kite's schema.

Potential future/validation sources include:

- official NSE historical trade datasets;
- Global Datafeeds;
- TrueData historical/bulk datasets;
- other licensed Indian-market historical-data vendors.

A future migration or supplementary feed must be possible without redesigning the ML feature contract.

## 4. Historical Dataset A — core 1-minute market history

Backfill approximately:

- **~2,000 equities** in the working StoX universe;
- **up to ~10 years** of history where the instrument/data exists;
- **1-minute OHLCV**;
- selected broad-market and sector indices at 1-minute granularity.

Canonical raw candle fields:

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

Where supported and useful, preserve additional source metadata needed for reproducibility and lineage.

Expected upper-order magnitude:

```text
2,000 instruments
× ~375 trading minutes/day
× ~250 trading days/year
× ~10 years
≈ 1.9 billion minute rows
```

Actual row count will be lower because instruments have different listing dates, suspensions, illiquidity and data availability.

## 5. Index and market-context history

Backfill relevant indices in addition to stocks. Initial candidates include:

- NIFTY 50;
- NIFTY 100;
- NIFTY 500;
- NIFTY Bank;
- NIFTY IT;
- NIFTY Auto;
- NIFTY Pharma;
- NIFTY Metal;
- NIFTY FMCG;
- other sector/thematic indices where they materially improve StoX research coverage.

Index data enables decomposition of stock movement into market, sector and stock-specific components and allows relative-return features such as:

```text
stock_return - market_return
stock_return - sector_return
```

The final index set is a research/configuration decision rather than a hard-coded product constant.

## 6. Backfill strategy and rate-limit handling

The backfill is intentionally **asynchronous/offline operational work** and is not latency sensitive.

Approximate full-universe request count for ten years of 1-minute data, assuming ~30-day request windows:

```text
~122 requests/instrument
× 2,000 instruments
≈ 244,000 historical API requests
```

At Kite's historical-data limit of approximately **3 requests/second**, the theoretical lower bound is around **23 hours** of continuously saturated requests. Real operation must allow for response latency, retries, rate limiting, authentication/session renewal, transient failures and data validation.

StoX does **not** require the full backfill to complete in one or two days. It is acceptable for the controlled importer to run for **days, weeks or months** if that reduces operational risk and keeps comfortably within provider limits.

Backfill requirements:

- configurable request pacing below provider limits;
- resumable/idempotent execution;
- per-instrument/date-window checkpoints;
- retry with bounded exponential backoff for transient failures;
- explicit handling of HTTP 429 and provider-side failures;
- no duplicate logical candles after retries;
- validation of expected trading-session timestamps where practical;
- durable error/dead-letter list for windows requiring later repair;
- progress/status reporting;
- ability to pause/resume without losing completed work;
- source/provenance retained with the dataset.

After initial history is present, incremental daily maintenance is expected to be comparatively small.

## 7. Analytical storage decision

### 7.1 Do not use MySQL as the primary minute-history analytical store

MySQL remains the StoX transactional/application database, but it should not be the primary repository for approximately 1.5–1.9 billion minute candles used for repeated analytical scans and ML feature construction.

MySQL remains appropriate for:

- users and application state;
- portfolios and transactions;
- fundamentals;
- existing daily market data where already appropriate;
- ML model metadata;
- model results/predictions required by the StoX UI;
- operational job metadata/checkpoints where useful.

### 7.2 Use Apache Parquet for bulk analytical history

Store large historical/derived ML datasets as **Parquet**.

Rationale:

- column-oriented storage;
- strong compression for repeated market-data structures;
- reads only required columns;
- predicate/row-group pruning;
- portable across Python, Polars, DuckDB, Spark, ClickHouse and other analytical tooling;
- no database-server or licence requirement;
- suitable for immutable/append-oriented historical datasets.

Expected order-of-magnitude storage for the core OHLCV history is approximately **50–120 GB compressed**, subject to final schema, partitioning, data types and compression settings. Derived features, staging data and backups will require additional space.

Initial infrastructure planning should therefore target roughly **500 GB–1 TB usable fast storage** rather than designing around the compressed core dataset alone.

## 8. Query/processing decision

Use **DuckDB + Polars/Python** as the initial analytical stack.

### DuckDB

Use DuckDB for:

- SQL directly over Parquet;
- large columnar scans;
- filtering and aggregation;
- partition/predicate pruning;
- dataset preparation without importing every row into a server database.

### Polars/Python

Use Polars/Python for:

- feature engineering;
- vectorised transformations;
- research notebooks/scripts;
- ML dataset construction;
- integration with model-training libraries.

### ClickHouse

Do **not** introduce ClickHouse initially.

ClickHouse becomes a future option if StoX needs:

- interactive analytical queries over billions of minute rows from the application;
- many concurrent analytical users;
- continuously queryable low-latency analytics over the large intraday corpus.

The initial use case is primarily offline/research/ML processing, for which Parquet + DuckDB is simpler and avoids another permanently operated database service.

## 9. Dataset partitioning and file-layout requirements

The exact physical partition strategy remains a detailed-design decision and should be selected based on dominant access patterns.

Candidate dimensions include:

- year/month;
- instrument;
- exchange;
- combinations of time and instrument buckets.

Requirements:

- avoid pathological numbers of tiny Parquet files;
- support efficient date-range scans;
- support efficient instrument-range scans;
- permit incremental append/rebuild;
- preserve deterministic partition naming;
- permit atomic replacement of rebuilt partitions;
- make source/ingestion version discoverable.

The design should benchmark realistic alternatives before freezing the layout.

## 10. Dataset A derived historical features

The historical core should support feature construction without requiring additional external data for every feature.

Candidate families include:

### Price / momentum

- 1m / 5m / 15m / 30m / 60m returns;
- rolling momentum;
- moving-average location/slope;
- momentum acceleration/deceleration;
- breakout/consolidation measures.

### Volatility / candle structure

- candle body;
- high-low range;
- upper/lower wick;
- realised volatility over multiple lookbacks;
- ATR / normalized ATR;
- volatility contraction/expansion;
- gap features.

### Volume

- rolling/relative volume;
- volume acceleration;
- volume breakout ratios;
- price-volume interaction features.

### Time/session context

- minute from market open;
- minute to market close;
- day of week;
- month;
- expiry-week/expiry-day indicators where relevant;
- month-end / quarter-end context.

These are candidate research features. V4-FEAT-058 decides the retained production feature set based on point-in-time correctness and out-of-sample value.

## 11. Cross-sectional and breadth features

The value of collecting the broad universe is not only per-stock history. The platform should support **same-timestamp cross-sectional context** across the market.

Candidate derived measures include:

- number/percentage of advancing and declining stocks;
- advance/decline ratio;
- percentage of stocks above previous close;
- percentage above selected moving averages;
- median universe return;
- cross-sectional return dispersion/volatility;
- return percentile/rank of each stock;
- volume percentile/rank;
- total/aggregate market volume where meaningful;
- new-high/new-low style breadth where reconstructable.

These features allow ML to study relationships where broad market state or movement in one group leads/follows another group.

## 12. Sector-relative context

Where StoX has reliable sector mapping/history, support derived context such as:

```text
stock_return - sector_return
stock_momentum - sector_momentum
stock_volatility / sector_volatility
stock_volume / sector_volume_or_peer_context
within-sector return rank
within-sector volume rank
```

Historical sector mapping must remain point-in-time safe where membership/classification changes matter. Do not leak current classifications into historical periods without explicit research justification.

## 13. Dataset B — derivative/Open-Interest enrichment

Where Kite supplies usable historical derivative data, evaluate:

```text
futures OHLCV
open_interest
OI change
price × OI interactions
volume × OI interactions
```

Possible research relationships include conditions commonly described as long buildup, short buildup, short covering and long unwinding; however these labels are not assumed to be predictive rules. The ML/research process should evaluate the raw/derived variables empirically.

Kite's historical expired-derivative coverage is more constrained than cash-equity OHLCV. Dataset B is therefore **secondary and opportunistic**, not a blocker for Dataset A.

## 14. Dataset C — prospective live microstructure

Starting when this epic is implemented, collect selected fields from Kite's **full WebSocket market-data feed** for future ML research.

Candidate source fields include:

- last traded price;
- last traded quantity;
- cumulative traded volume;
- average traded price;
- total buy quantity;
- total sell quantity;
- Open Interest and OI high/low where applicable;
- five bid levels;
- five ask levels;
- price at each level;
- quantity at each level;
- order count at each level.

This data is not expected to be backfillable at comparable long historical depth through Kite, so StoX should begin accumulating it prospectively.

## 15. Do not retain every raw WebSocket tick by default

Persisting every exchange/WebSocket update indefinitely could increase storage and processing requirements dramatically.

The initial direction is to aggregate prospective microstructure into **1-minute feature bars** aligned with the historical Dataset A cadence.

Candidate 1-minute microstructure aggregates include:

```text
avg/min/max bid-ask spread
relative spread
avg/closing order-book imbalance
avg/max bid depth
avg/max ask depth
order-count imbalance
microprice / microprice deviation
avg/max trade size
trade count / trade intensity
avg buy quantity
avg sell quantity
average traded price
OI
OI change
```

Raw tick retention may later be enabled for a limited research subset or bounded retention period if an experiment demonstrates that sub-minute information materially improves the desired prediction horizon.

## 16. Three-generation ML data model

Treat the research data as three logical generations.

### Dataset A — immediately backfillable core

```text
~10 years 1-minute OHLCV
+ indices
+ cross-sectional market context
+ market breadth
+ sector-relative context
+ derived price/volume/volatility/time features
```

This dataset is sufficient to begin serious ML research and is the first implementation priority.

### Dataset B — derivative enrichment

```text
futures/options data where historically usable
+ Open Interest
+ derivative interaction features
```

Secondary, subject to historical coverage constraints.

### Dataset C — prospective proprietary microstructure

```text
bid/ask
5-level depth
order counts
trade-size/intensity
buy/sell imbalance
average traded price
other minute-aggregated microstructure measures
```

This begins accumulating after implementation and becomes progressively more valuable with time.

## 17. ML research philosophy

Do not delay initial research waiting for Dataset B or C.

Start by testing whether stable signal exists using:

> **price + volume + market + sector + cross-sectional context**

Only then measure the incremental value of:

- Open Interest/derivatives;
- order book/depth;
- trade-microstructure variables;
- increasingly complex model families.

Every additional feature family should justify itself through chronological out-of-sample evidence rather than intuition.

## 18. Point-in-time correctness and leakage controls

All research datasets and derived features must satisfy StoX ML point-in-time requirements.

At minimum:

- features at timestamp `T` use only information known at or before `T`;
- future candles are never included in feature construction;
- rolling calculations terminate at the observation timestamp;
- cross-sectional features use only same-time or earlier information;
- fitted preprocessing uses training data only;
- historical universe/sector/index membership should be reconstructed where required rather than blindly substituting current membership;
- train/validation/test splits remain chronological with appropriate horizon embargo/purge rules;
- data-source corrections/corporate-action adjustments must be handled consistently and provenance/versioned where they can alter prior observations.

## 19. Survivorship-bias limitation

Using only today's Kite instrument universe to reconstruct the past may omit historically listed companies that later failed, delisted, merged or otherwise disappeared.

This can introduce **survivorship bias** into historical cross-sectional research.

V8 may proceed with Kite for initial research, but the limitation must be explicitly recorded in dataset metadata/research reports.

If StoX later requires scientifically rigorous historical-universe backtesting, evaluate official NSE or licensed historical datasets that include dead/delisted securities and historical security-master information.

The architecture must permit such a future source without changing the ML feature/query contract.

## 20. Data-quality and validation requirements

The ingestion/research pipeline SHOULD measure and expose:

- missing minute intervals;
- duplicate candles;
- impossible/non-monotonic timestamps;
- zero/negative invalid prices where not legitimately expected;
- suspicious volume values;
- unexplained large jumps;
- corporate-action boundaries where relevant;
- source-request failures and repaired windows;
- instrument listing/delisting availability boundaries;
- per-instrument/year coverage percentage.

Do not silently fill missing prices in the canonical raw dataset. Any imputation/resampling used for a model must be explicit, reproducible and feature/dataset-versioned.

## 21. Cost direction

Initial architecture intentionally avoids paid enterprise analytical databases.

Software/licence cost for the analytical stack:

- Apache Parquet: **₹0**;
- DuckDB: **₹0**;
- Polars/Python: **₹0**;
- self-hosted analytical processing: infrastructure cost only.

Kite Connect market-data/historical subscription is expected to be approximately **₹500/month**, subject to Zerodha's current pricing at implementation time.

Infrastructure cost is primarily storage, CPU and RAM. For the expected compressed core dataset, a modest single-node environment with fast local NVMe storage should be sufficient for initial research; a distributed enterprise data lake is not required at this stage.

ClickHouse Cloud, Snowflake, BigQuery, Spark/Hadoop clusters or other managed enterprise analytics are explicitly **not required for the initial V8 implementation**.

## 22. Initial implementation stages

### Stage 1 — POC

Use a representative subset, e.g. tens of stocks plus relevant indices, to validate:

- Kite authentication/history retrieval;
- actual historical depth/coverage;
- real observed API throughput;
- retry/resume behaviour;
- Parquet schema and compression;
- candidate partition layouts;
- DuckDB/Polars query speed;
- basic feature generation;
- point-in-time dataset construction.

### Stage 2 — full historical backfill

Expand to the target universe and run controlled resumable backfill until available history is complete.

### Stage 3 — Dataset A research

Build cross-sectional, market, sector, technical and volume features and evaluate predictive value under V4-FEAT-058/V4-FEAT-059 rules.

### Stage 4 — prospective microstructure capture

Start Kite WebSocket full-mode collection and minute aggregation so Dataset C begins accumulating without blocking Dataset A research.

### Stage 5 — optional enrichment

Evaluate derivative/OI data and/or licensed alternate historical sources only when research demonstrates a clear need.

## 23. Initial acceptance criteria

1. StoX can authenticate to the configured Kite data source without embedding user secrets in code or datasets.
2. A resumable historical importer can retrieve 1-minute OHLCV for a configured instrument/date range while honouring provider rate limits.
3. Re-running an already completed window is idempotent and does not create duplicate logical candles.
4. Import progress and failed/retryable windows are observable.
5. Raw minute history is persisted as versioned/provenanced Parquet rather than primarily as billions of MySQL rows.
6. DuckDB can query the Parquet corpus directly without a mandatory database import step.
7. Polars/Python can build ML-ready datasets from the same canonical files.
8. Selected market/sector indices can be backfilled and aligned with stock timestamps.
9. Cross-sectional/breadth datasets can be reconstructed point-in-time from the historical universe available to the platform.
10. Storage partitioning avoids pathological tiny-file proliferation and supports efficient time/instrument filtering.
11. Dataset quality reports expose missing/duplicate/suspicious intervals rather than silently hiding them.
12. Dataset A can produce reproducible feature matrices for chronological ML research.
13. Current-survivor/universe limitations and survivorship-bias risk are documented with each applicable research dataset.
14. Prospective Kite WebSocket full-mode data can be aggregated into 1-minute microstructure feature bars.
15. Raw full-tick retention is not required for the initial production design.
16. Existing MySQL application responsibilities remain intact; the analytical lake does not replace transactional storage.
17. ClickHouse or an enterprise data lake is not required to complete the initial V8 scope.
18. The architecture can later incorporate another historical market-data provider without changing the logical ML feature contract.

## 24. Open design decisions

Detailed V8 implementation should decide:

- final target instrument universe and historical-universe handling;
- exact Kite date-window size used by the throttled importer;
- operational request rate below the provider maximum;
- credential/token refresh and unattended backfill operating model;
- exact Parquet schema/data types/compression codec;
- physical partition/file layout;
- raw vs cleaned/canonical dataset layering;
- dataset/catalog/version-manifest format;
- storage location: local NVMe initially versus object storage;
- exact index list;
- historical sector-classification source;
- treatment of non-trading/missing minutes and illiquid instruments;
- corporate-action validation/reconciliation policy;
- whether any historical futures/OI data is included in the first implementation;
- exact WebSocket sampling/aggregation algorithm;
- whether a bounded raw-tick buffer is useful for debugging/reprocessing;
- feature-store boundary between reusable materialized features and on-demand feature computation;
- resource limits for local ML jobs so they do not affect StoX production workloads;
- backup/retention policy for raw history versus regenerable derived features.

## 25. Boundary and relationship to other V8 ML epics

**V4-FEAT-062 owns:**

- Kite intraday historical ingestion;
- resumable/rate-limited backfill;
- Parquet analytical storage architecture;
- DuckDB/Polars analytical access;
- index/history acquisition needed for intraday ML context;
- broad dataset-quality/provenance controls;
- prospective WebSocket/microstructure capture and minute aggregation;
- the logical Dataset A/B/C data generations.

**V4-FEAT-058 owns:**

- the detailed technical/market feature definitions;
- feature-selection research;
- retained production feature set;
- evidence that additional features improve model performance.

**V4-FEAT-059 owns:**

- repeated chronological validation;
- horizon-aware model-evaluation/promotion criteria;
- evidence required before an expanded model becomes eligible for promotion.

**V4-FEAT-056 owns:**

- scheduled retraining/deployment operations once the selected datasets/features become part of the production ML lifecycle.

This epic therefore provides the **intraday data foundation** on which those ML capabilities can operate, while keeping acquisition/storage concerns separate from feature and model decisions.
