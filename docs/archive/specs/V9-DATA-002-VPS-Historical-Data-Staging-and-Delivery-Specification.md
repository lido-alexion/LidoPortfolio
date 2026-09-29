# V9-DATA-002 — VPS Historical Data Staging and Delivery

| Field | Value |
|---|---|
| **Epic** | V9-DATA-002 |
| **Status** | **FROZEN / IMPLEMENTATION-READY** |
| **Issue** | [V9-DATA-002](https://github.com/lido-alexion/LidoPortfolio/issues/16) |
| **Related Mac app** | [SKR-001 — StoX-Kite-Rain](https://github.com/lido-alexion/StoX-Kite-Rain/issues/1) |
| **Companion spec** | [Mac downloader specification](https://github.com/lido-alexion/StoX-Kite-Rain/blob/main/docs/SK-001-MacOS-Downloader-Specification.md) |
| **Inherited V8 specification** | [`V8-Intraday-ML-Historical-Data-Platform-Specification.md`](V8-Intraday-ML-Historical-Data-Platform-Specification.md) — V4-FEAT-065 |

## 1. Goal

V9-DATA-002 changes the acquisition/delivery topology of the frozen FEAT-065 historical-data platform without redefining its canonical research corpus. The StoX VPS, whose static IP is whitelisted by Kite, fetches historical 1-minute OHLCV, stages immutable verified trading-day batches, and exposes a secure pull surface to StoX-Kite-Rain. The Mac remains the canonical research-data home.

Kite requests originate only from the VPS. The Mac never stores Kite credentials and never needs inbound connectivity, SSH, or a VPN. StoX-Kite-Rain initiates authenticated outbound HTTPS pulls to the dedicated transfer endpoint.

## 2. Inherited FEAT-065 contract

The following V8 decisions remain normative and SHALL NOT be reopened by implementation:

- MacBook Pro remains the primary historical-data, research and heavy offline ML machine.
- Apache Parquet remains the canonical minute-level storage format.
- Current NIFTY 500 fixed universe remains the equity universe; survivorship bias remains explicitly accepted.
- Configured broad-market and sector indices remain part of the corpus.
- Zerodha Kite Connect historical API remains the initial provider, while normalized schema and analytics remain provider-agnostic.
- Acquisition remains resumable, restart-safe and idempotent with durable checkpoints, bounded retry/backoff, explicit 429/error handling, duplicate prevention, failed-window tracking, progress reporting and pause/resume.
- DuckDB remains the SQL analytical engine over Parquet; Polars/Python remains the dataframe/feature-construction stack.
- Downstream datasets remain point-in-time safe.
- FEAT-063 prospective live microstructure remains a separate domain.
- Dataset B derivative/OI enrichment remains optional and non-blocking.
- ClickHouse is not introduced initially.
- No automated backup subsystem is added for the Mac corpus; existing manual external-disk backup remains the operational assumption.

## 3. Explicit V9 overrides/additions

1. **History depth:** initial bootstrap attempts the maximum provider-available 1-minute OHLCV for every in-scope instrument instead of FEAT-065's earlier arbitrary eight-year ceiling.
2. **Acquisition topology:** provider acquisition runs from the whitelisted StoX VPS.
3. **Temporary staging:** the VPS may temporarily hold historical payloads solely for reliable delivery to the Mac.
4. **Delivery unit:** one sealed base batch represents exactly one trading day; a day-batch may contain multiple Parquet files.
5. **Supplemental repair batches:** a later repair for an already-imported day is a separate immutable supplemental batch containing only previously missing logical rows. It never overwrites accepted candles.
6. **Accepted-data immutability:** once a day-batch is successfully imported and acknowledged, later provider corrections are ignored. Accepted candles are not silently refreshed or mutated.

## 4. Collection schedule and completeness

- Incremental collection runs once per trading day after market close, default **18:00 IST**.
- The official market calendar determines trading vs non-trading days. Known holidays produce no empty batch and are not gaps.
- Latest completed trading day is prioritized before bounded historical gap repair.
- Failed provider requests receive bounded automatic retry/backoff before being marked unresolved.
- Incomplete trading days remain unresolved and are retried on later runs; an incomplete day SHALL NOT be published READY.
- Each run may also repair a bounded backlog of known failed/missing historical windows.
- Admin may trigger **Retry unresolved gaps** and may re-queue a specific trading day only when it is unresolved or missing.
- Already accepted/imported days cannot be force-refreshed.

## 5. Batch lifecycle

The frozen lifecycle is:

1. **COLLECTING** — provider windows are fetched into a working area using FEAT-065 resumability/checkpoint semantics.
2. **ASSEMBLING** — normalized data for one trading day is assembled; request-window boundaries do not define transfer boundaries.
3. **VALIDATING** — completeness, schema, coverage and semantic checks run on the VPS.
4. **READY** — a versioned manifest is sealed and the batch becomes immutable.
5. **LEASED** — StoX-Kite-Rain owns the batch for transfer/import under a renewable lease.
6. **ACKNOWLEDGED** — the Mac has durably and atomically imported the exact batch and acknowledged the exact batch ID + manifest hash.
7. **DELETION-ELIGIBLE** — lifecycle retention conditions are met.
8. **DELETED** — payload removed; lightweight retained metadata remains according to the audit policy.

Collection may continue while a Mac transfer is active, but only into separate working areas/new batches. A READY/LEASED batch can never be mutated by the collector.

## 6. Manifest and READY invariant

A sealed manifest SHALL contain at least:

- batch ID and batch kind (`base` or `supplemental_repair`);
- trading date and covered market/session bounds;
- manifest version and schema version;
- seal timestamp;
- normalized instrument identities and source instrument tokens where relevant;
- per-file path, byte size, SHA-256, row count and schema identity;
- aggregate file/row counts;
- expected vs actual instrument count where determinable;
- expected vs actual row count where determinable;
- missing instruments/windows;
- market-calendar identity/version;
- completeness status;
- canonical manifest hash;
- source/provenance metadata required by FEAT-065.

**READY means the VPS semantic completeness rules have passed.** The Mac is not required to duplicate the VPS semantic-completeness engine; it verifies transport/file integrity, Parquet readability, schema compatibility and safe import.

Manifest canonicalization/versioning is an implementation detail, but a protocol version change must be coordinated with SKR-001.

## 7. Transfer API and security

A dedicated public HTTPS endpoint SHALL be used, e.g. `data.stoxla.in`.

This public machine surface exposes only:

- READY-batch discovery;
- lease acquisition/renewal/release;
- resumable download;
- acknowledgment;
- minimal device-authenticated transfer status required by StoX-Kite-Rain;
- a small fixed-size speed-test object served from the same endpoint/path class.

Collector controls, gap retries, quota operations, enrollment/revocation and diagnostics remain behind normal authenticated StoX Admin UI and are not exposed as public transfer operations.

Security requirements:

- public TLS validated through normal trusted certificate/hostname validation; no certificate pinning required;
- standard HTTP byte-range support for resumable downloads;
- dedicated device transfer credential distinct from Kite and user browser sessions;
- one-time short-lived enrollment code generated in StoX Admin and exchanged over HTTPS;
- only **one enrolled Mac/device at a time**;
- device credential is long-lived until explicitly revoked/replaced;
- credential stored in macOS Keychain and never placed in URLs/logs;
- no inbound listener on the Mac;
- path traversal and cross-batch access rejected;
- rate/size limits applied to the transfer API.

## 8. Lease semantics

- One batch lease at a time is sufficient because only one Mac may be enrolled.
- Lease duration: **30 minutes**.
- Lease renews automatically while downloading, verifying, importing, or explicitly paused.
- Explicit **Pause** has no maximum duration and continues lightweight lease renewal.
- **Stop** releases the lease.
- Successful acknowledgment immediately releases the lease.
- If the app crashes/disappears and cannot renew, lease expiry makes the batch recoverable.
- Repeated identical acknowledgment for the same device, batch ID and manifest hash is idempotently successful; a conflicting manifest hash is rejected.

## 9. VPS quota and retention

- Default staging quota: **20 GB**, configurable operationally.
- All staged payloads, including acknowledged payloads still within grace period, count against the same real disk quota.
- At quota/safe-threshold pressure, new historical collection pauses/defer and Admin sees an operational warning.
- Quota pressure SHALL NOT silently purge an otherwise protected active batch.
- Acknowledged payloads are retained **7 days**, then automatically deleted if not leased and no active lifecycle transition exists.
- Failed/rejected payloads become deletion-eligible after **30 days** even if never acknowledged.
- If a failed/rejected batch is superseded by a successful replacement that is imported and acknowledged, the superseded failed payload becomes immediately deletion-eligible.
- There is no Admin retention-hold feature.
- Admin cannot manually delete READY or unacknowledged batches.

## 10. Deleted-batch metadata and audit

After payload deletion, retain lightweight metadata for **1 year**, including at least:

- batch ID;
- trading date;
- manifest hash;
- batch kind;
- acknowledgment timestamp where applicable;
- enrolled-device identity;
- deletion timestamp;
- deletion reason, such as `acknowledged_retention_expired`, `failed_rejected_expired`, or `superseded_by_successful_replacement`.

VPS transfer/audit logs are retained **30 days** and cover enrollment/revocation, discovery, lease lifecycle, downloads, acknowledgment, deletion and failures.

The Admin UI provides searchable recent deleted-batch metadata by trading date, batch ID, manifest hash, acknowledgment/deletion timestamp and deletion reason.

## 11. Admin operations UI

Provide a minimal Admin operations surface showing:

- current staging usage vs 20 GB/default configured quota;
- collector state;
- latest complete trading day;
- unresolved gaps;
- READY / leased / acknowledged / failed batch states;
- current enrolled Mac/device;
- revoke/replace enrollment;
- recent operational errors;
- deleted-batch metadata history.

Gap diagnostics are trading-day level by default, with expandable per-instrument/window details.

Allowed safe actions:

- Retry unresolved gaps;
- re-queue a specific unresolved/missing trading day;
- revoke/replace the enrolled Mac.

Do not expose arbitrary payload deletion or low-level file mutation.

## 12. Ordering and repair rules

- VPS collection priority: latest completed trading day first, then bounded historical gap repair.
- Mac download/import priority: oldest unresolved READY base or required supplemental repair batch first.
- A problematic oldest required batch blocks advancement; later batches are not silently skipped.
- Supplemental repair batches add only genuinely missing logical rows and never overwrite accepted rows.
- Base and supplemental batches are each atomic logical units for verification/import/acknowledgment.

## 13. Parquet/storage rules

- A trading-day batch may contain multiple Parquet files.
- Physical file count/target size remains an FEAT-065/POC implementation-tuning concern, not a product requirement.
- The Mac imports the entire day-batch atomically; individual files are not committed piecemeal.
- No immediate compaction is required after each import. Compaction may be introduced later only if measured file-count/query-performance evidence justifies it.
- Existing FEAT-065 corpus is adopted non-destructively; V9 must not create a competing canonical tree.

## 14. Frozen PO decisions

The following new V9/SKR decisions are frozen:

- maximum provider-available historical bootstrap;
- one trading day per sealed base batch;
- 7-day acknowledged payload grace period;
- 20 GB default configurable VPS staging quota;
- failed/rejected payload expiry after 30 days;
- no retention hold;
- automatic deletion according to lifecycle rules;
- one enrolled Mac/device only;
- one-time Admin enrollment code + long-lived revocable device credential;
- dedicated public HTTPS transfer subdomain;
- normal public TLS validation, no certificate pinning;
- standard HTTP range requests;
- 30-minute renewable batch lease;
- idempotent acknowledgment;
- 1-year deleted-batch metadata retention;
- 30-day VPS transfer/audit log retention;
- latest-day-first collection and bounded gap repair;
- oldest-required-batch-first Mac processing;
- no skipping of a blocking problematic batch;
- provider corrections to already accepted candles are ignored;
- supplemental immutable repair batches may fill only genuinely missing rows;
- minimal Admin operational UI with safe retry/re-enrollment actions only.

## 15. Implementation-delegated details

The implementation agent may choose without further PO approval, provided the frozen behavior above is preserved:

- exact provider request-window sizes and pacing constants;
- manifest serialization/canonicalization details;
- endpoint route names and payload field naming;
- lease-renewal cadence below the 30-minute expiry;
- exact filesystem atomic-publish technique;
- exact Parquet compression/file sizing;
- retry/backoff constants within bounded-retry rules;
- internal scheduler mechanism;
- speed-test object size within the frozen small fixed-size 5–10 MB tuning range.

## 16. Acceptance criteria

V9-DATA-002 is complete only when:

1. VPS can backfill the maximum provider-available history for the inherited NIFTY 500 + configured index universe with FEAT-065 resumability/idempotency/coverage semantics.
2. Daily incremental collection runs after market close and never publishes an incomplete day as READY.
3. Holidays/non-trading days are handled by market calendar without false gaps.
4. READY batches are immutable, one-trading-day logical units with complete semantic/integrity manifests.
5. Supplemental repair batches can fill missing rows without mutating accepted values.
6. Concurrent collection and transfer cannot expose partial/mutating READY data.
7. Transfer API is authenticated, resumable via byte ranges, least-privilege, and isolated to machine-transfer functions.
8. One-time enrollment produces one revocable enrolled Mac credential; replacement/revocation works.
9. Lease, Pause/Stop recovery, crash recovery and idempotent acknowledgment pass automated tests.
10. Mac acknowledgment occurs only after full atomic durable import.
11. Quota pressure pauses/defer collection instead of unsafe purge.
12. 7-day/30-day retention rules and automatic cleanup are enforced exactly.
13. Deleted-batch metadata and audit retention meet the frozen periods.
14. Admin UI exposes the frozen minimal operational/diagnostic surface and safe retry actions.
15. Existing FEAT-065 Parquet corpus remains canonical, readable and compatible with DuckDB/Polars.
16. Tests cover checksum mismatch, schema rejection, incomplete day, stale lease, duplicate ack, crash between import and ack, low quota, failed-batch expiry, supplemental repair and blocking oldest-batch behavior.

**Document state: FROZEN / IMPLEMENTATION-READY.** No remaining PO decision is required unless implementation discovers a direct contradiction with FEAT-065 or a materially new product behavior.