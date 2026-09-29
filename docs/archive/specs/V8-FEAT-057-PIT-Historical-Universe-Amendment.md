# StoX V8 FEAT-057 — PIT Historical Universe & Sector Context Amendment

| Field | Value |
|---|---|
| **Parent feature** | V4-FEAT-057 — ML Feature Engineering, Model Training & Validation |
| **Status** | FROZEN — normative correction discovered during production acceptance |
| **Parent specification** | `docs/archive/specs/V8-ML-Feature-Engineering-Training-Validation-Specification.md` |
| **Implementation issue** | GitHub issue #18 |
| **Reason** | Production acceptance found missing historical-universe population and present-day sector leakage into historical ML rows |

## 1. Normative correction

The parent specification already requires point-in-time-safe market/breadth/sector context and states that a feature which cannot be reconstructed honestly must be excluded rather than approximated with present-day knowledge.

Production acceptance found that `MlTrainingDatasetBuilder` currently constructs historical categorical sector rows from current `portfolio_stocks.sector`. That is not PIT-safe.

The following rules supersede any implementation or audit statement that conflicts with them.

### 1.1 Sector-derived features

Until authoritative effective-dated sector snapshots exist:

- `sector` is **challenger / evidence-required**, not mandatory core.
- `sector_relative_strength_3m` is **challenger / evidence-required**, not mandatory core.
- Historical `sector` must resolve only from the effective-dated `sector_snapshot` applicable to the row reference date.
- If no dated sector snapshot exists, categorical sector is `__unknown`.
- If no dated sector snapshot exists, sector-relative strength is `null`.
- Current `portfolio_stocks.sector` must never be projected backward into historical training rows.
- Reclassifying these features is an immutable registry change and requires a feature-registry version bump.

## 2. Historical `active_eligible_nse` membership source policy

StoX must build historical membership only from contemporaneous NSE evidence.

Source hierarchy:

1. Prefer the NSE dated **MII Security File — NSE Listed securities** when NSE's historical-reports service actually provides the requested date.
2. Otherwise use contemporaneous NSE cash-market historical bhavcopy / PR evidence for company-equity series `EQ`, `BE`, and `BZ`.
3. For bhavcopy-based fallback, exclude fund/ETF instruments from the company-equity universe. Company securities use company ISIN identity (`INE...`); fund/ETF identities such as `INF...` are not included in this membership dataset.
4. Resolve canonical StoX identity by ISIN first where available; historical symbol is secondary.
5. Never substitute today's StoX universe for a missing historical date.

## 3. Snapshot quality gate

Every generated historical snapshot must persist or otherwise expose auditable source diagnostics:

- requested/effective date;
- source type and source identifier/file;
- source format/version (legacy bhavcopy, UDiFF, MII Security File, etc.);
- total company-equity source members;
- canonically mapped members;
- unknown/unmapped members and identifiers;
- mapping percentage;
- parser/version identity.

A snapshot with less than **90% canonical mapping coverage** must fail/skip rather than be materialized.

This 90% floor is evidence-based. Production reconnaissance against the current StoX NSE master produced:

| Date | Source family | Canonical mapping |
|---|---|---:|
| 2022-08-29 | legacy NSE bhavcopy | 93.01% |
| 2023-06-30 | legacy NSE bhavcopy | 96.29% |
| 2024-06-28 | legacy NSE bhavcopy | 97.57% |
| 2024-08-30 | NSE UDiFF bhavcopy | 97.94% |
| 2025-05-30 | NSE UDiFF bhavcopy | 98.61% |
| 2026-05-29 | NSE UDiFF bhavcopy | 99.87% |

The earliest representative sample also recovered 79 renamed securities through ISIN mapping that direct symbol matching would have missed.

## 4. Membership and sector evidence are independent

A valid historical membership snapshot does not imply historical sector classification is known.

Therefore:

- membership can be materialized with `sector_snapshot = null`;
- market-breadth features may use valid membership independently;
- sector-derived features remain unknown/null unless an authoritative dated sector value exists;
- today's sector value is not an acceptable substitute.

This distinction allows V8 to use honest PIT breadth context without fabricating historical sector information.

## 5. Required implementation

Codex must implement the following without weakening the parent FEAT-057 specification:

1. Add a reproducible NSE historical-universe archive builder/backfill path. Manual hand-crafted JSON is not the operational solution.
2. Support legacy cash-market bhavcopy before the July-2024 UDiFF transition and UDiFF thereafter.
3. Prefer MII Security File when the requested historical date is available; fall back to contemporaneous bhavcopy/PR evidence when NSE reports it unavailable.
4. Filter to company-equity `EQ`/`BE`/`BZ` membership and apply stable ISIN-first identity mapping.
5. Enforce the >=90% mapping quality gate before materialization.
6. Persist source/mapping diagnostics and unmapped identifiers for audit.
7. Reuse the existing durable `MlHistoricalUniverseMembershipService`, snapshot-boundary and backfill-run model rather than inventing parallel lifecycle storage.
8. Make backfill idempotent and resumable.
9. Change historical categorical `sector` construction to effective-dated membership `sectorForDate(stock_id, reference_date)`; use `__unknown` when absent.
10. Reclassify both `sector` and `sector_relative_strength_3m` as challenger/evidence-required and bump the immutable feature-registry version.
11. Add regression tests for current-sector leakage, ISIN rename mapping, ETF/fund exclusion, legacy/UDiFF parsing, mapping-quality rejection, idempotency, missing-date failure and the prohibition on current-universe fallback.

## 6. Production acceptance gate

Before FEAT-056 lifecycle automation may be enabled:

1. Build/backfill every historical reference date required by a fresh 1m/3m/6m FEAT-057 campaign.
2. `membership_coverage` must report **100% requested reference-date coverage** for that campaign.
3. Every materialized reference-date snapshot must independently satisfy >=90% canonical mapping coverage.
4. `market_breadth_nifty` must show non-zero historical feature coverage.
5. `sector` and `sector_relative_strength_3m` must remain unknown/null where no authoritative dated sector snapshot exists.
6. Fresh production 1m/3m/6m training must complete through the real Python adapter and persist feature coverage/exclusion evidence.
7. No automatic promotion may occur.
8. Only after this gate passes should FEAT-056 worker/SSE/cancellation/recovery and bounded scheduling acceptance continue.

## 7. Non-goals

- Do not redefine the historical universe as NIFTY500.
- Do not create historical facts by copying today's stock master.
- Do not infer historical sectors from today's sector classification.
- Do not lower PIT requirements simply to make production acceptance green.
- Do not enable automatic model promotion.
