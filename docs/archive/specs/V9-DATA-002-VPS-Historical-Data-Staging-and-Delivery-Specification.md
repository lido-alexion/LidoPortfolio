# V9-DATA-002 — VPS Historical Data Staging and Delivery

| Field | Value |
|---|---|
| **Epic** | V9-DATA-002 |
| **Status** | **PO REVIEW — ARCHITECTURE DRAFT** |
| **Issue** | [V9-DATA-002](https://github.com/lido-alexion/LidoPortfolio/issues/16) |
| **Related Mac app** | [SKR-001 — StoX-Kite-Rain](https://github.com/lido-alexion/StoX-Kite-Rain/issues/1) |
| **Companion spec** | [Mac downloader specification](https://github.com/lido-alexion/StoX-Kite-Rain/blob/main/docs/SK-001-MacOS-Downloader-Specification.md) |
| **Inherited V8 specification** | [`V8-Intraday-ML-Historical-Data-Platform-Specification.md`](V8-Intraday-ML-Historical-Data-Platform-Specification.md) — V4-FEAT-065 |
| **V8 boundary** | V9-DATA-002 changes acquisition/staging/delivery topology while inheriting the frozen FEAT-065 corpus, quality, storage and research contracts unless explicitly overridden below. |

## 1. Goal

Use the StoX VPS, whose static IP is whitelisted by Kite, to fetch historical 1-minute OHLCV data, hold it in bounded staging storage, and deliver sealed batches to the Mac when StoX-Kite-Rain automatically syncs over an eligible connection. The Mac remains the canonical research-data home. VPS copies are temporary and must not be deleted until the Mac has verified and committed them and acknowledged the exact batch.

This epic is an operational/topology extension of V4-FEAT-065, not a replacement historical-data product. FEAT-065 remains the authority for the canonical research corpus and its downstream analytical contract except where this V9 specification explicitly supersedes a V8 choice.

## 2. Frozen FEAT-065 decisions inherited by V9-DATA-002

The following FEAT-065 decisions are already product-approved and SHALL NOT be reopened by this epic:

- the MacBook Pro remains the primary historical-data, research and heavy offline ML machine;
- Apache Parquet remains the canonical historical storage format; MariaDB/MySQL is not the minute-level canonical store;
- the equity universe remains the current NIFTY 500 fixed universe, with survivorship bias explicitly accepted;
- selected broad-market and sector indices remain part of the historical corpus;
- Zerodha Kite Connect historical API remains the initial historical source, while normalized storage/analytics stay provider-agnostic;
- historical acquisition remains resumable, restart-safe and idempotent with durable checkpoints, bounded retry/backoff, explicit 429/error handling, duplicate prevention, failed-window tracking, progress reporting and pause/resume support;
- DuckDB remains the SQL analytical engine over Parquet and Polars/Python remains the dataframe/feature-construction stack;
- downstream datasets remain point-in-time safe;
- prospective live microstructure remains separately owned by V4-FEAT-063;
- derivative/open-interest enrichment remains optional Dataset B work and must not block the core OHLCV corpus;
- ClickHouse is not introduced initially;
- canonical data and metadata remain organized for straightforward manual external-disk backup; StoX does not introduce an automated backup subsystem for this corpus;
- approved model artifacts may later move to the VPS without requiring the full historical corpus to reside there.

### Explicit V9 overrides/additions

V9-DATA-002 changes or adds only the following frozen product behavior relative to FEAT-065:

1. **History depth override:** the initial bootstrap SHALL attempt the **maximum historical 1-minute OHLCV available from the configured provider** for every in-scope instrument, rather than retaining FEAT-065's earlier arbitrary "up to 8 years" ceiling. Actual coverage remains subject to listing date, provider availability, suspensions and source gaps.
2. **Acquisition topology:** Kite historical requests originate from the whitelisted StoX VPS rather than requiring the Mac to perform provider acquisition directly.
3. **Temporary VPS staging:** acquired historical data may exist temporarily on the VPS solely for reliable staged delivery to the canonical Mac corpus.
4. **Transfer batch boundary:** one sealed transfer batch represents **one trading day** across the relevant in-scope instruments/data available for that day. Provider request windows may be smaller or instrument-specific internally; those request work units do not redefine the sealed delivery batch.
5. **Mac delivery application:** StoX-Kite-Rain performs automatic authenticated outbound HTTPS discovery/download/verification/import/acknowledgment from Mac to VPS.

All other FEAT-065 product decisions continue unchanged unless a later explicit V9 decision says otherwise.

## 3. System boundaries

- **V9-DATA-002 (this epic):** Kite historical fetching from whitelisted VPS egress, resumable VPS checkpoints, staging, immutable daily manifests/batches, secure pull endpoints, transfer leases, acknowledgment verification, retention/deletion, and VPS operations.
- **SKR-001 / StoX-Kite-Rain:** Mac menu-bar app, network eligibility/speed policy, automatic discovery and pull, local validation/import into the FEAT-065 canonical corpus, progress controls, and UI.
- **V4-FEAT-065:** canonical normalized OHLCV/index corpus semantics, Parquet authority, research-machine role, quality/coverage expectations, source abstraction, PIT safety and DuckDB/Polars analytical access.
- The two V9/SKR specs share a versioned manifest and transfer protocol. A protocol change requires coordinated updates to both specs.
- Kite requests originate only from the whitelisted VPS. The Mac initiates outbound HTTPS pulls; the VPS never connects inbound to the Mac. No Mac Kite credential, inbound Mac port, or SSH tunnel is part of this design.
- FEAT-063 live microstructure remains a separate data domain.

## 4. Proposed lifecycle

These architectural requirements remain under PO review only where explicitly identified as open later in this document:

1. **Collect:** fetch bounded Kite historical request windows to a VPS working area. Persist checkpoints so failed work can resume without skipping ranges. Apply inherited FEAT-065 retry/backoff, rate-limit, failed-window and progress semantics.
2. **Assemble daily batch:** normalize provider responses into the inherited FEAT-065 data contract and assemble one trading-day delivery batch. Holidays/non-trading days do not produce empty required batches.
3. **Seal:** validate completed files; create a versioned manifest and calculate file sizes and SHA-256 hashes; atomically publish the result as READY. READY batches and their manifests are immutable.
4. **Discover and lease:** the Mac lists READY batches and obtains an expiring lease for a batch. A lease protects it from deletion or simultaneous claim. Expired leases make interrupted work recoverable; they do not delete data.
5. **Download:** the Mac writes to temporary local files and resumes only against the same batch ID and manifest hash. New Kite collection writes to separate working files/batches and cannot mutate READY data.
6. **Verify and import:** the Mac checks files against the manifest, checks Parquet readability/schema/counts, and atomically commits to its canonical FEAT-065 corpus. Repeated imports must be idempotent.
7. **Acknowledge:** after durable local commit, the Mac sends batch ID plus manifest hash. VPS accepts only an exact match to its sealed manifest.
8. **Retain then delete:** acknowledged data waits through a configurable grace period, then becomes eligible for deletion. Interrupted, rejected, or unacknowledged batches remain available. VPS quota/expiry rules remain to be frozen.

The collector must never modify files being transferred. Collection and published batches use separate working/published areas. Collection can continue during transfer only by creating new working data or batches.

## 5. Manifest and integrity rules

A manifest SHALL contain batch ID, manifest/schema versions, seal time, covered trading date/ranges, instrument identity/token, and per-file path, byte size, SHA-256, row count, and schema identity. It SHALL also include aggregate file/row counts and a canonical manifest hash.

Canonical candle identity/value semantics inherit FEAT-065 fields, including at least instrument identity, exchange, trading symbol, timestamp, OHLCV, source, source instrument token and schema version. The transfer protocol may add non-destructive delivery/provenance fields without changing FEAT-065 analytical semantics.

The Mac rejects a batch if files are missing, size/hash differs, Parquet cannot be read, schema is unsupported, or manifest counts disagree. A rejected batch is never acknowledged or deleted.

Manifest encoding/canonicalization, compatibility policy and transfer-level semantic validation are technical design choices unless they create a new user-visible compatibility or retention policy.

## 6. Idempotency and canonical storage

- Never append to or mutate a READY VPS batch.
- Retry work and duplicate delivery must converge to the same logical FEAT-065 corpus.
- Candle duplicate identity SHALL be based on the existing normalized FEAT-065 instrument identity plus candle timestamp; Kite transport token alone must not become the permanent analytical key.
- The Mac canonical destination remains schema-versioned Parquet with deterministic organization suitable for DuckDB/Polars and manual external-disk backup.
- Existing FEAT-065 canonical files are authoritative existing corpus state; V9/SKR import must adopt them non-destructively rather than create a competing canonical tree.
- Import must not expose partial partitions. The implementation may use temporary files, atomic rename/publish, compaction and deterministic merge as needed, provided existing valid corpus data remains readable and duplicate logical rows are prevented.
- Transfer retry after a crash must reconcile server lease, batch manifest, local temporary files and committed state before resuming.

Exact local file sizing/compaction remains an implementation choice, consistent with FEAT-065's rule to avoid pathological tiny-file proliferation while preserving useful date/instrument pruning.

## 7. Completeness, corrections and recovery

FEAT-065 already requires explicit coverage and missingness reporting by instrument/date range, failed-window visibility and repairability. V9-DATA-002 inherits those requirements.

Therefore:

- a trading-day batch must not be represented as complete merely because some files were successfully fetched;
- known unavailable/suspended/unlisted instruments or provider gaps must be represented in coverage metadata rather than fabricated;
- failed request windows remain retryable and visible;
- a later provider correction or repaired gap must be applied reproducibly and must not silently mutate prior research provenance;
- non-trading exchange holidays do not constitute missing trading-day batches.

The exact automated recheck cadence for incomplete/repaired days may be selected by implementation so long as retries are bounded, observable and do not violate provider limits.

## 8. Races, recovery and quota

A per-batch lease serializes transfer lifecycle operations. It protects the batch from deletion; immutability protects it from collector mutation. These are separate guarantees.

Network loss, Mac sleep/quit, process crash, checksum failure or Parquet failure leaves the VPS copy intact. VPS disk pressure must stop/defer new fetches before it threatens unacknowledged batches. It must report quota pressure and must never silently purge an unacknowledged batch.

Lease duration/renewal/takeover mechanics are implementation-level unless they materially change user-visible Pause/Stop behavior. VPS staging quota, acknowledged deletion grace period and any operator disposition of indefinitely unacknowledged batches remain product/operational decisions to freeze.

## 9. Security and operator visibility

Provide authenticated TLS endpoints for batch discovery, lease/renew/release, resumable download, acknowledgment and status. Keep Kite credentials on the VPS; use a separate revocable credential for Mac transfer access. Confine paths to a batch, validate manifests, apply rate/size limits, redact secrets, and audit claim/download/ack/delete.

Credential mechanics, endpoint naming/routing and protocol encoding are technical implementation choices, provided the credential is least-privilege, independently revocable, protected in macOS Keychain, never exposed in URLs/logs, and no inbound listener/SSH requirement is introduced on the Mac.

## 10. Draft acceptance criteria

1. VPS fetches the maximum provider-available historical 1-minute OHLCV for the current NIFTY 500 fixed universe plus configured indices, subject to actual source availability.
2. Acquisition honors the inherited FEAT-065 source-normalization, resumability, idempotency, rate-limit, failed-window, provenance, coverage and PIT-safety contracts.
3. Delivery batches are sealed on a one-trading-day boundary and atomically published with valid manifests.
4. Published batches remain immutable while READY or leased.
5. Mac discovers and pulls through authenticated outbound HTTPS and safely resumes a simulated interruption.
6. Concurrent collection creates separate working data and cannot make a transfer miss, read partial, or delete files.
7. Corrupt/incomplete/wrong-schema files are rejected without acknowledgment or VPS deletion.
8. A durable valid Mac commit into the existing FEAT-065 canonical Parquet corpus followed by exact acknowledgment transitions only that batch toward delayed deletion.
9. Duplicate transfers and acknowledgments are idempotent; duplicate logical candle rows are not created.
10. Expired leases recover without data loss.
11. Quota pressure never silently deletes unacknowledged data.
12. Existing valid FEAT-065 corpus files remain readable and are adopted non-destructively.
13. DuckDB/Polars analytical access and the manual-backup-friendly canonical structure remain intact after V9 imports.
14. Tests cover interruption, concurrent collection/transfer, stale lease, duplicate ack, checksum mismatch, incomplete trading day, repair/correction, and crashes between local commit and ack.

## 11. Remaining PO decisions before freeze

The FEAT-065 reconciliation removes historical-corpus questions that were already frozen. The remaining product decisions are specific to the new staging/delivery behavior:

1. **VPS retention policy:** staging quota, acknowledged-batch grace period, and operator handling of indefinitely unacknowledged batches.
2. **Mac auto-sync/network policy:** enabled-by-default behavior, eligible Wi-Fi/network rules, minimum download-speed policy, optional bandwidth ceiling and retry/recheck behavior.
3. **User controls:** exact Pause / Stop / Resume semantics. **Automatic sync only** remains the confirmed normal trigger; there is no normal manual Start action.
4. **Mac application UX:** menu-bar-only versus companion settings/status window, and the minimum user-visible sync history/status needed.
5. **macOS distribution/runtime policy:** portable bundle expectations, signing/notarization, minimum macOS version, update behavior and launch-at-login default.

The following are delegated to architecture/implementation and are no longer PO questions unless implementation uncovers a material product tradeoff: canonical manifest serialization/version field mechanics, request-window sizing, lease timers/renewal protocol, HTTP endpoint names, credential token format/rotation mechanics, atomic filesystem technique, Parquet compaction algorithm, and retry backoff constants.

## 12. Related work

- Inherited V8 historical-data specification: [`V8-Intraday-ML-Historical-Data-Platform-Specification.md`](V8-Intraday-ML-Historical-Data-Platform-Specification.md)
- VPS epic: [V9-DATA-002 issue](https://github.com/lido-alexion/LidoPortfolio/issues/16)
- Mac epic: [SKR-001 issue](https://github.com/lido-alexion/StoX-Kite-Rain/issues/1)
- Mac spec: [SK-001 specification](https://github.com/lido-alexion/StoX-Kite-Rain/blob/main/docs/SK-001-MacOS-Downloader-Specification.md)

**Document state:** architecture draft for PO review. FEAT-065 inheritance, maximum-provider-history override and one-trading-day sealed batch boundary are frozen. Do not implement remaining open product behavior until the decisions in Section 11 are resolved and both companion specs are frozen.
