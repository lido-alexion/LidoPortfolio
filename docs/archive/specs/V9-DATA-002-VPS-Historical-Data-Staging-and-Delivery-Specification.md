# V9-DATA-002 — VPS Historical Data Staging and Delivery

| Field | Value |
|---|---|
| **Epic** | V9-DATA-002 |
| **Status** | **PO REVIEW — ARCHITECTURE DRAFT** |
| **Issue** | [V9-DATA-002](https://github.com/lido-alexion/LidoPortfolio/issues/16) |
| **Related Mac app** | [SKR-001 — StoX-Kite-Rain](https://github.com/lido-alexion/StoX-Kite-Rain/issues/1) |
| **Companion spec** | [Mac downloader specification](https://github.com/lido-alexion/StoX-Kite-Rain/blob/main/docs/SK-001-MacOS-Downloader-Specification.md) |
| **V8 boundary** | New V9 work. Frozen V8 FEAT-065 records remain unchanged. |

## 1. Goal

Use the StoX VPS, whose static IP is whitelisted by Kite, to fetch historical 1-minute OHLCV data, hold it in bounded staging storage, and deliver sealed batches to the Mac when StoX-Kite-Rain automatically syncs over an eligible connection. The Mac remains the canonical research-data home. VPS copies are temporary and must not be deleted until the Mac has verified and committed them and acknowledged the exact batch.

## 2. System boundaries

- **V9-DATA-002 (this epic):** Kite historical fetching, resumable VPS checkpoints, staging, immutable manifests, secure pull endpoints, transfer leases, acknowledgment verification, retention/deletion, and VPS operations.
- **SKR-001 / StoX-Kite-Rain:** Mac menu-bar app, network eligibility/speed policy, automatic discovery and pull, local validation/import, progress controls, and UI.
- The two specs share a versioned manifest and transfer protocol. A protocol change requires coordinated updates to both specs.
- Kite requests originate only from the whitelisted VPS. The Mac initiates outbound HTTPS pulls; the VPS never connects inbound to the Mac. No Mac Kite credential, inbound Mac port, or SSH tunnel is part of this design.
- FEAT-063 live microstructure remains a separate data domain.

## 3. Proposed lifecycle

These are proposed architectural requirements for PO review, not yet frozen:

1. **Collect:** fetch bounded Kite historical windows to a VPS working area. Persist checkpoints so failed work can resume without skipping ranges.
2. **Seal:** validate completed files; create a versioned manifest and calculate file sizes and SHA-256 hashes; atomically publish the result as READY. READY batches and their manifests are immutable.
3. **Discover and lease:** the Mac lists READY batches and obtains an expiring lease for a batch. A lease protects it from deletion or simultaneous claim. Expired leases make interrupted work recoverable; they do not delete data.
4. **Download:** the Mac writes to temporary local files and resumes only against the same batch ID and manifest hash. New Kite collection writes to separate working files/batches and cannot mutate READY data.
5. **Verify and import:** the Mac checks files against the manifest, checks Parquet readability/schema/counts, and atomically commits to its canonical corpus. Repeated imports must be idempotent.
6. **Acknowledge:** after durable local commit, the Mac sends batch ID plus manifest hash. VPS accepts only an exact match to its sealed manifest.
7. **Retain then delete:** acknowledged data waits through a configurable grace period, then becomes eligible for deletion. Interrupted, rejected, or unacknowledged batches remain available. VPS quota/expiry rules require PO agreement.

The collector must never modify files being transferred. Collection and published batches use separate working/published areas. Collection can continue during transfer only by creating new working data or batches.

## 4. Proposed manifest and integrity rules

A manifest should contain batch ID, manifest/schema versions, seal time, covered market dates and ranges, instrument identity/token, and per-file path, byte size, SHA-256, row count, and schema identity. It should also include aggregate file/row counts and a canonical manifest hash.

The Mac rejects a batch if files are missing, size/hash differs, Parquet cannot be read, schema is unsupported, or manifest counts disagree. A rejected batch is never acknowledged or deleted. Manifest encoding/canonicalization, compatibility policy, and semantic row checks remain open.

## 5. Idempotency and storage

- Never append to a READY Parquet file. Publish immutable files as separate batches.
- Retry work and duplicate delivery must converge to the same logical corpus.
- Proposed logical key: instrument token plus candle timestamp; PO confirmation is required against the existing FEAT-065 schema.
- Mac import must not expose partial partitions. Temporary files plus atomic publish/rename are proposed; merge, compaction, conflict handling, and adoption of existing FEAT-065 files remain open.
- Transfer retry after a crash must reconcile server lease, batch manifest, local temporary files, and committed state before resuming.

## 6. Races, recovery, quota

A per-batch lease serializes transfer lifecycle operations. Lease owner, duration, renewal and takeover policy remain open. It protects the batch from deletion; immutability protects it from collector mutation. These are separate guarantees.

Network loss, Mac sleep/quit, process crash, checksum failure or Parquet failure leaves the VPS copy intact. VPS disk pressure must stop/defer new fetches before it threatens unacknowledged batches. It must report quota pressure and must never silently purge an unacknowledged batch. Exact quota, expiry and operator recovery rules remain open.

## 7. Security and operator visibility

Provide authenticated TLS endpoints for batch discovery, lease/renew/release, resumable download, acknowledgment and status. Keep Kite credentials on the VPS; use a separate revocable credential for Mac transfer access. Confine paths to a batch, validate manifests, apply rate/size limits, redact secrets, and audit claim/download/ack/delete. Auth bootstrap, credential rotation, endpoint names/routing and operator UI are not frozen.

## 8. Draft acceptance criteria

1. VPS fetches a bounded Kite range using whitelisted egress and resumes from durable checkpoints.
2. Completed batches are atomically published with valid manifests and remain immutable while READY or leased.
3. Mac discovers and pulls through authenticated outbound HTTPS and safely resumes a simulated interruption.
4. Concurrent collection creates separate data and cannot make a transfer miss, read partial, or delete files.
5. Corrupt/incomplete/wrong-schema files are rejected without acknowledgment or VPS deletion.
6. A durable valid Mac commit followed by exact acknowledgment transitions only that batch toward delayed deletion.
7. Duplicate transfers and acknowledgments are idempotent; expired leases recover without data loss.
8. Quota pressure never silently deletes unacknowledged data.
9. Tests cover interruption, concurrent collection/transfer, stale lease, duplicate ack, checksum mismatch, and crashes between local commit and ack.

## 9. Open PO decisions before freeze

1. Collection schedule, initial history depth, market-day completeness/watermark, holidays, correction/retry policy.
2. Batch boundaries, target/maximum size, and oversized-session handling.
3. VPS staging quota, acknowledged grace period, and safe expiry/recovery for unacknowledged batches.
4. Mac layout, existing-corpus adoption, merge/compaction, duplicate resolution, and atomic commit behavior.
5. Manifest format/versioning, lease duration/renewal, resume contract, and ack retention.
6. Transfer credential enrollment, refresh, revocation, and server authentication.
7. Wi-Fi/SSID policy, minimum download speed, measurement method, retry behavior, bandwidth ceiling.
8. Exact Pause/Stop/Resume/retry semantics. **Automatic sync only** is the confirmed PO choice; manual Start is not the normal trigger.
9. Portable packaging, signing/notarization and launch-at-login behavior.

## 10. Related work

- VPS epic: [V9-DATA-002 issue](https://github.com/lido-alexion/LidoPortfolio/issues/16)
- Mac epic: [SKR-001 issue](https://github.com/lido-alexion/StoX-Kite-Rain/issues/1)
- Mac spec: [SK-001 specification](https://github.com/lido-alexion/StoX-Kite-Rain/blob/main/docs/SK-001-MacOS-Downloader-Specification.md)

**Document state:** architecture draft for PO review. Do not implement as frozen behavior until the open decisions are resolved.
